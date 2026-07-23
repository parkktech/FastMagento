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

        // one pass: every option of every select/multiselect attribute, default-store label w/ admin fallback
        $select = $conn->select()
            ->from(['o' => $tOpt], ['attribute_id', 'option_id', 'sort_order'])
            ->join(['a' => $tAttr], 'a.attribute_id = o.attribute_id', [])
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

        while ($row = $stmt->fetch()) {
            $aid = (int) $row['attribute_id'];
            if ($current !== null && $aid !== $current) {
                $docs[] = ['attribute_id' => $current, 'options' => $buffer];
                $buffer = [];
                $count++;
                if (count($docs) >= 200) {
                    $flush($docs);
                }
            }
            $current = $aid;
            $buffer[] = [
                'value' => (string) $row['option_id'],
                'label' => (string) ($row['label'] ?? ''),
                'sort' => (int) $row['sort_order'],
            ];
        }
        if ($current !== null) {
            $docs[] = ['attribute_id' => $current, 'options' => $buffer];
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
