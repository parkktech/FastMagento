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
 * attribute's full {option_id => label} set into one OpenSearch doc per attribute (default store
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
        private readonly LoggerInterface $logger
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
                foreach (($src['options'] ?? []) as $o) {
                    $options[] = ['value' => (string) ($o['value'] ?? ''), 'label' => (string) ($o['label'] ?? '')];
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

    /** Label for one option id, or null on a miss (caller falls back to native). */
    public function getOptionText(int $attributeId, string $optionId): ?string
    {
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
                $res = $client->search([
                    'index' => $this->config->getAttributeOptionIndexName(),
                    'body' => [
                        'size' => 1000,
                        '_source' => ['attribute_id', 'attribute_code'],
                        'query' => ['exists' => ['field' => 'attribute_code']],
                    ],
                ]);
                foreach (($res['hits']['hits'] ?? []) as $hit) {
                    $src = $hit['_source'] ?? [];
                    $code = (string) ($src['attribute_code'] ?? '');
                    $id = (int) ($src['attribute_id'] ?? 0);
                    if ($code !== '' && $id > 0) {
                        $this->codeMap[$code] = $id;
                    }
                }
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
            return 0;
        }
        $index = $this->config->getAttributeOptionIndexName();
        $storeId = $this->defaultStoreId();
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');
        $tAttr = $this->resource->getTableName('eav_attribute');
        $swatchByOption = $this->loadSwatches($conn, $storeId);

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

        // stream rows and flush per-attribute so a 50k-option attribute never balloons memory
        $stmt = $conn->query($select);
        $current = null;
        $buffer = [];
        $docs = [];
        $count = 0;
        $flush = function (array &$docs) use ($client, $index): void {
            if (!$docs) {
                return;
            }
            $lines = '';
            foreach ($docs as $d) {
                $lines .= json_encode(['index' => ['_id' => (string) $d['attribute_id'], '_index' => $index]]) . "\n";
                $lines .= json_encode($d) . "\n";
            }
            $lines .= "\n";
            $resp = $client->bulk(['body' => $lines]);
            if (!empty($resp['errors'])) {
                $this->logger->error('[FastMagento] OptionDictionary bulk errors: ' . json_encode($resp));
            }
            $docs = [];
        };

        $currentCode = '';
        while ($row = $stmt->fetch()) {
            $aid = (int) $row['attribute_id'];
            if ($current !== null && $aid !== $current) {
                $docs[] = ['attribute_id' => $current, 'attribute_code' => $currentCode, 'options' => $buffer];
                $buffer = [];
                $count++;
                if (count($docs) >= 200) {
                    $flush($docs);
                }
            }
            $current = $aid;
            $currentCode = (string) ($row['attribute_code'] ?? '');
            $buffer[] = [
                'value' => (string) $row['option_id'],
                'label' => (string) ($row['label'] ?? ''),
                'sort' => (int) $row['sort_order'],
                'swatch' => $swatchByOption[(int) $row['option_id']] ?? null,
            ];
        }
        if ($current !== null) {
            $docs[] = ['attribute_id' => $current, 'attribute_code' => $currentCode, 'options' => $buffer];
            $count++;
        }
        $flush($docs);

        // make freshly-written docs immediately gettable
        try {
            $client->indices()->refresh(['index' => $index]);
        } catch (\Throwable $e) {
            // non-fatal
        }
        $this->logger->info("[FastMagento] OptionDictionary rebuilt: {$count} attributes (store {$storeId}).");
        return $count;
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
            $res = $client->search([
                'index' => $this->config->getAttributeOptionIndexName(),
                'body' => [
                    'size' => 200,
                    '_source' => ['options'],
                    'query' => ['terms' => ['options.value.keyword' => array_map('strval', $missing)]],
                ],
            ]);
            $wanted = array_flip(array_map('intval', $missing));
            foreach ($res['hits']['hits'] ?? [] as $hit) {
                foreach ($hit['_source']['options'] ?? [] as $option) {
                    $id = (int) ($option['value'] ?? 0);
                    if (isset($wanted[$id])) {
                        $this->swatchMemo[$id] = $option['swatch'] ?? null;
                    }
                }
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
