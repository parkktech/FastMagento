<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Search\Model\EngineResolver;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Psr\Log\LoggerInterface;

/**
 * OpenSearch-backed attribute-option label dictionary.
 *
 * Native Magento resolves a select/multiselect option id to its display label through the
 * attribute source model, which loads `eav_attribute_option(_value)` from MySQL — so every PDP
 * additional-attributes row, layered-nav facet and swatch on a served page still hits the DB even
 * though the product itself is OpenSearch-served. This service projects each option-bearing
 * attribute's {option_id => label} set into small, independently addressed option documents (default store
 * view labels, admin fallback) and serves the labels back, so those lookups leave MySQL entirely.
 *
 * Read path: getOptions()/getOptionText() fetch one attribute's doc (cached per request). On any
 * miss (index absent, attribute not projected, OS error) the caller falls back to native — the
 * dictionary is a fast path, never a source of truth.
 */
class OptionDictionary
{
    /** @var array<int,array<int,array{value:string,label:string}>> per-request cache: attrId => options */
    private array $cache = [];

    /** @var array<string,int>|null per-request cache: attributeCode => attrId (null = not loaded) */
    private ?array $codeMap = null;

    /** @var array<int,array<string,string>> per-request cache: attrId => {optionId => label} */
    private array $labelCache = [];

    private ?object $client = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolver $engineResolver,
        private readonly OpenSearchConfig $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly \ParkkTech\FastMagento\Model\OpenSearch\BoundedBulkWriter $bulkWriter
    ) {
    }

    /** Whether the dictionary should be consulted at all (master switch, default on). */
    public function isEnabled(): bool
    {
        $v = $this->scopeConfig->getValue('fastmagento/indexing/serve_option_labels');
        return $v === null ? true : (bool) $v;
    }

    /**
     * All options for an attribute, in sort order: [['value' => '86', 'label' => 'Black'], ...].
     * Empty array on any miss (caller falls back to native).
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function getOptions(int $attributeId): array
    {
        $this->resetStoreMemo();
        if ($attributeId <= 0 || !$this->isEnabled()) {
            return [];
        }
        if (isset($this->cache[$attributeId])) {
            return $this->cache[$attributeId];
        }
        $options = [];
        try {
            $client = $this->getClient();
            if ($client) {
                $res = $client->get([
                    'index' => $this->config->getAttributeOptionIndexName(),
                    'id' => (string) $attributeId,
                ]);
                $src = $res['_source'] ?? [];
                $rows = !empty($src['inline_options_complete']) || (int)($src['format_version'] ?? 1) < 2
                    ? ($src['options'] ?? []) : $this->readOptionRows($client, $attributeId);
                foreach ($rows as $o) {
                    $label = isset($o['labels']) ? ($o['labels'][$this->memoStoreId] ?? $o['labels'][0] ?? '') : ($o['label'] ?? '');
                    $options[] = ['value' => (string)($o['value'] ?? ''), 'label' => (string)$label];
                }
            }
        } catch (\Throwable $e) {
            // index/doc missing or OS down — degrade to native
            $options = [];
        }
        $this->cache[$attributeId] = $options;
        $this->labelCache[$attributeId] = [];
        foreach ($options as $o) {
            $this->labelCache[$attributeId][$o['value']] = $o['label'];
        }
        return $options;
    }

    private function readOptionRows(object $client, int $attributeId): array
    {
        $out = []; $after = null;
        do {
            $body = ['size' => 100, '_source' => ['option'],
                'query' => ['bool' => ['filter' => [
                    ['term' => ['kind' => 'option']], ['term' => ['attribute_id' => $attributeId]],
                ]]], 'sort' => [['sort_order' => 'asc'], ['option_id' => 'asc']]];
            if ($after !== null) { $body['search_after'] = $after; }
            $response = $client->search(['index' => $this->config->getAttributeOptionIndexName(), 'body' => $body]);
            $hits = $response['hits']['hits'] ?? [];
            foreach ($hits as $hit) { $out[] = $hit['_source']['option']; }
            $after = $hits ? end($hits)['sort'] : null;
        } while (count($hits) === 100);
        return $out;
    }

    /** Label for one option id, or null on a miss (caller falls back to native). */
    public function getOptionText(int $attributeId, string $optionId): ?string
    {
        $this->resetStoreMemo();
        if ($optionId === '' || !$this->isEnabled()) {
            return null;
        }
        if (!isset($this->labelCache[$attributeId])) {
            $this->getOptions($attributeId); // populates labelCache
        }
        $map = $this->labelCache[$attributeId] ?? [];
        return array_key_exists($optionId, $map) ? $map[$optionId] : null;
    }

    /**
     * attribute_code => attribute_id for every attribute in the dictionary, resolved in ONE
     * OpenSearch call with the (potentially huge) options array excluded from _source.
     *
     * Facet buckets only carry the attribute code, while the dictionary is keyed by id — this is
     * the bridge. Kept off the DB deliberately: the whole point of the dictionary is that label
     * resolution never touches EAV.
     *
     * @return array<string,int>
     */
    public function getAttributeIdsByCode(): array
    {
        if ($this->codeMap !== null) {
            return $this->codeMap;
        }
        $this->codeMap = [];
        if (!$this->isEnabled()) {
            return $this->codeMap;
        }
        try {
            $client = $this->getClient();
            if ($client) {
                $after = null;
                do {
                    $body = ['size' => 500, '_source' => ['attribute_id', 'attribute_code'],
                        'query' => ['exists' => ['field' => 'attribute_code']],
                        'sort' => [['attribute_id' => 'asc']]];
                    if ($after !== null) { $body['search_after'] = $after; }
                    $res = $client->search(['index' => $this->config->getAttributeOptionIndexName(), 'body' => $body]);
                    $hits = $res['hits']['hits'] ?? [];
                    foreach ($hits as $hit) {
                        $src = $hit['_source'] ?? [];
                        $code = (string)($src['attribute_code'] ?? '');
                        $id = (int)($src['attribute_id'] ?? 0);
                        if ($code !== '' && $id > 0) { $this->codeMap[$code] = $id; }
                    }
                    $after = $hits ? end($hits)['sort'] : null;
                } while (count($hits) === 500);
            }
        } catch (\Throwable $e) {
            // index missing or OS down — caller degrades to whatever label it already had
            $this->codeMap = [];
        }
        return $this->codeMap;
    }

    /**
     * Label for one option id addressed by attribute CODE. Null on a miss.
     *
     * This is what makes attribute facets work on a configurable catalog. The native fulltext
     * index stores option ids on {code} and labels on {code}_value, but on a multi-value document
     * (any configurable parent, which carries every child's colour/size) those two arrays sort
     * independently, so an id cannot be paired with its label from the document alone. The
     * dictionary is an explicit id => label map, so the multi-value case stops mattering.
     */
    public function getOptionTextByCode(string $attributeCode, string $optionId): ?string
    {
        if ($attributeCode === '' || $optionId === '' || !$this->isEnabled()) {
            return null;
        }
        $attributeId = $this->getAttributeIdsByCode()[$attributeCode] ?? 0;
        if ($attributeId <= 0) {
            return null;
        }
        return $this->getOptionText($attributeId, $optionId);
    }

    // ── indexing ─────────────────────────────────────────────────────────────────────────────

    /**
     * Rebuild the whole dictionary: one doc per option-bearing attribute, options resolved to the
     * default store view label (admin fallback). Returns the number of attributes projected.
     */
    public function rebuild(): int
    {
        $client = $this->getClient();
        if (!$client) {
            $this->logger->error('[FastMagento] OptionDictionary: no OpenSearch client.');
            throw new \RuntimeException('Option dictionary rebuild requires an OpenSearch client.');
        }
        $index = $this->config->getAttributeOptionIndexName();
        $storeId = $this->defaultStoreId();
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');
        $tAttr = $this->resource->getTableName('eav_attribute');
        $swatchesByStore = [];
        foreach (array_unique(array_merge([0], array_keys($this->storeManager->getStores()))) as $sid) {
            $swatchesByStore[(int)$sid] = $this->loadSwatches($conn, (int)$sid);
        }
        $labelsByOption = [];
        foreach ($conn->fetchAll($conn->select()->from($tVal, ['option_id','store_id','value'])) as $label) {
            $labelsByOption[(int)$label['option_id']][(int)$label['store_id']] = $label['value'];
        }

        // one pass: every option of every select/multiselect attribute, default-store label w/ admin fallback
        $select = $conn->select()
            ->from(['o' => $tOpt], ['attribute_id', 'option_id', 'sort_order'])
            // attribute_code is projected alongside the id so the read path can resolve a facet's
            // code (which is all a facet carries) to its dictionary doc without touching EAV.
            //
            // Scoped to catalog_product on purpose: attribute codes are only unique WITHIN an
            // entity type, and Magento ships real collisions (`gender` is both a customer and a
            // product attribute; `page_layout` and `custom_design` are both category and product).
            // Emitting the code for every entity type would let one overwrite the other in the
            // code map and resolve a product facet against a customer attribute's options.
            // Non-product attributes still get a doc — they are simply addressed by id only.
            ->join(
                ['a' => $tAttr],
                'a.attribute_id = o.attribute_id',
                []
            )
            ->joinLeft(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                "et.entity_type_id = a.entity_type_id AND et.entity_type_code = 'catalog_product'",
                []
            )
            ->columns(['attribute_code' => new \Zend_Db_Expr('IF(et.entity_type_id IS NULL, NULL, a.attribute_code)')])
            ->joinLeft(['va' => $tVal], 'va.option_id = o.option_id AND va.store_id = 0', [])
            ->joinLeft(['vs' => $tVal], 'vs.option_id = o.option_id AND vs.store_id = ' . (int) $storeId, [])
            ->columns(['label' => new \Zend_Db_Expr('COALESCE(vs.value, va.value)')])
            ->where('a.frontend_input IN (?)', ['select', 'multiselect'])
            ->order('o.attribute_id')->order('o.sort_order')->order('o.option_id');

        // One option per document keeps a 50k-option attribute out of any single request.
        // Build privately, validate, then swap the alias. Failed builds leave the old dictionary.
        $generation = $index . '_v' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
        $client->indices()->create(['index' => $generation, 'body' => ['mappings' => ['properties' => [
            'kind' => ['type' => 'keyword'], 'attribute_id' => ['type' => 'integer'],
            'attribute_code' => ['type' => 'keyword'], 'option_id' => ['type' => 'long'],
            'sort_order' => ['type' => 'integer'], 'option' => ['type' => 'object', 'enabled' => false],
            'options' => ['type' => 'object', 'enabled' => false],
        ]]]]);
        $count = 0;
        $aliasSwapAttempted = false;
        try {
            $documents = (function () use ($conn, $select, $swatchesByStore, $labelsByOption, $storeId, &$count): \Generator {
                $stmt = $conn->query($select);
                $current = null; $currentCode = ''; $inline = []; $inlineBytes = 0;
                $header = static function ($id, $code, $inline) use ($storeId): array {
                    return ['id' => (string)$id, 'body' => ['kind' => 'attribute', 'attribute_id' => $id,
                        'attribute_code' => $code, 'format_version' => 2, 'store_id' => $storeId,
                        'inline_options_complete' => $inline !== null, 'options' => $inline ?? []]];
                };
                while ($row = $stmt->fetch()) {
                    $aid = (int)$row['attribute_id'];
                    if ($current !== $aid) {
                        if ($current !== null) { yield $header($current, $currentCode, $inline); }
                        $current = $aid; $currentCode = (string)($row['attribute_code'] ?? ''); $count++;
                        $inline = []; $inlineBytes = 0;
                    }
                    $option = [
                        'value' => (string)$row['option_id'], 'label' => (string)($row['label'] ?? ''),
                        'sort' => (int)$row['sort_order'],
                        'labels' => $labelsByOption[(int)$row['option_id']] ?? [],
                        'swatches' => array_map(static fn($map) => $map[(int)$row['option_id']] ?? null, $swatchesByStore),
                    ];
                    if ($inline !== null) {
                        $inlineBytes += strlen(json_encode($option, JSON_THROW_ON_ERROR));
                        if ($inlineBytes <= 65536) { $inline[] = $option; } else { $inline = null; }
                    }
                    yield ['id' => 'option_' . $row['option_id'], 'body' => [
                        'kind' => 'option', 'attribute_id' => $aid, 'option_id' => (int)$row['option_id'],
                        'sort_order' => (int)$row['sort_order'], 'option' => $option,
                    ]];
                }
                if ($current !== null) { yield $header($current, $currentCode, $inline); }
            })();
            $written = $this->bulkWriter->write($client, $generation, $documents);
            $client->indices()->refresh(['index' => $generation]);
            $actual = (int)($client->count(['index' => $generation])['count'] ?? -1);
            if ($actual !== $written) { throw new \RuntimeException('Option dictionary document count does not match the source.'); }
            $actions = [];
            $oldIndices = [];
            if ($client->indices()->existsAlias(['name' => $index])) {
                $oldIndices = array_keys($client->indices()->getAlias(['name' => $index]));
                foreach ($oldIndices as $old) { $actions[] = ['remove' => ['index' => $old, 'alias' => $index]]; }
            } elseif ($client->indices()->exists(['index' => $index])) {
                $actions[] = ['remove_index' => ['index' => $index]];
            }
            $actions[] = ['add' => ['index' => $generation, 'alias' => $index]];
            $aliasSwapAttempted = true;
            $client->indices()->updateAliases(['body' => ['actions' => $actions]]);
            foreach ($oldIndices as $old) {
                if (str_starts_with($old, $index . '_v')) {
                    try { $client->indices()->delete(['index' => $old]); } catch (\Throwable $e) {
                        $this->logger->warning('[FastMagento] Old option index cleanup failed: ' . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            // An alias acknowledgement can time out after the swap committed. Never delete
            // a potentially active generation on that ambiguous failure.
            if (!$aliasSwapAttempted) {
                try { $client->indices()->delete(['index' => $generation]); } catch (\Throwable $cleanup) {}
            }
            throw $e;
        }
        $this->cache = []; $this->labelCache = []; $this->codeMap = null; $this->swatchMemo = [];
        $this->logger->info("[FastMagento] OptionDictionary rebuilt atomically: {$count} attributes (store {$storeId}).");
        return $count;
    }

    private ?int $memoStoreId = null;
    private function resetStoreMemo(): void
    {
        $id = (int)$this->storeManager->getStore()->getId();
        if ($this->memoStoreId !== $id) {
            $this->memoStoreId = $id; $this->cache = []; $this->labelCache = []; $this->swatchMemo = [];
        }
    }

    private function defaultStoreId(): int
    {
        try {
            return (int) $this->storeManager->getDefaultStoreView()->getId();
        } catch (\Throwable $e) {
            return 1;
        }
    }

    /** Low-level OpenSearch client (the same one the product indexer uses). */
    /**
     * Swatch row per option for the served store: the store-specific row when it has a value,
     * otherwise the admin (store 0) row — the same precedence Magento\Swatches\Helper\Data
     * applies when it reads eav_attribute_option_swatch.
     *
     * @return array<int, array{swatch_id:string, option_id:string, store_id:string, type:string, value:string}>
     */
    private function loadSwatches($conn, int $storeId): array
    {
        $out = [];
        try {
            $select = $conn->select()
                ->from(
                    $this->resource->getTableName('eav_attribute_option_swatch'),
                    ['swatch_id', 'option_id', 'store_id', 'type', 'value']
                )
                ->where('store_id IN (?)', [0, $storeId])
                ->order('store_id ASC');   // admin row first, store row overrides
            foreach ($conn->fetchAll($select) as $row) {
                $optionId = (int) $row['option_id'];
                if ((int) $row['store_id'] === $storeId && (string) $row['value'] === '' && isset($out[$optionId])) {
                    continue;   // empty store-level text falls back to admin, as the helper does
                }
                $out[$optionId] = [
                    'swatch_id' => (string) $row['swatch_id'],
                    'option_id' => (string) $row['option_id'],
                    'store_id' => (string) $row['store_id'],
                    'type' => (string) $row['type'],
                    'value' => (string) ($row['value'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[FastMagento] OptionDictionary: swatch load failed: ' . $e->getMessage());
        }
        return $out;
    }

    /** @var array<int, array|null> option_id => swatch row (null = option known, no swatch) */
    private array $swatchMemo = [];

    /**
     * Swatch rows for these option ids from the dictionary, keyed by option id and holding
     * only options that have a swatch — the shape Magento\Swatches\Helper\Data returns.
     * NULL when any option is unknown to the dictionary (index behind): let the query answer.
     *
     * @param int[] $optionIds
     * @return array<int, array>|null
     */
    public function getSwatchesByOptionIds(array $optionIds): ?array
    {
        $this->resetStoreMemo();
        $optionIds = array_values(array_unique(array_filter($optionIds)));
        if (!$optionIds || !$this->isEnabled()) {
            return null;
        }
        $missing = array_filter($optionIds, fn ($id) => !array_key_exists($id, $this->swatchMemo));
        if ($missing) {
            $client = $this->getClient();
            if (!$client) {
                return null;
            }
            // v2 uses a directly addressed, small document for each option.
            try {
                $res = $client->mget(['index' => $this->config->getAttributeOptionIndexName(),
                'body' => ['ids' => array_map(static fn ($id) => 'option_' . $id, array_values($missing))]]);
            } catch (\Throwable $e) { return null; }
            foreach ($res['docs'] ?? [] as $hit) {
                if (empty($hit['found'])) { continue; }
                $option = $hit['_source']['option'] ?? [];
                $id = (int)($option['value'] ?? 0);
                if ($id) { $this->swatchMemo[$id] = isset($option['swatches']) ? ($option['swatches'][$this->memoStoreId] ?? $option['swatches'][0] ?? null) : ($option['swatch'] ?? null); }
            }
            foreach ($missing as $id) {
                if (!array_key_exists($id, $this->swatchMemo)) {
                    return null;
                }
            }
        }
        $out = [];
        foreach ($optionIds as $id) {
            if (!empty($this->swatchMemo[$id])) {
                $out[$id] = $this->swatchMemo[$id];
            }
        }
        return $out;
    }

    private function getClient(): ?object
    {
        if ($this->client !== null) {
            return $this->client;
        }
        try {
            $engine = $this->engineResolver->getCurrentSearchEngine();
            $this->client = $this->clientResolver->create($engine)->getOpenSearchClient();
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento] OptionDictionary client error: ' . $e->getMessage());
            $this->client = null;
        }
        return $this->client;
    }
}
