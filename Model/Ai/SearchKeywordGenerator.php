<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Ai;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Setup\Patch\Data\AddSearchKeywordsAttribute;

/**
 * Populates the `fm_search_keywords` product attribute with an AI-generated keyword/synonym
 * layer, in resumable batches, off the request path (CLI / cron only — never inline).
 *
 * For a batch of products it sends each product's name + key attribute labels + a short
 * description snippet to the model and asks for a compact, comma-separated list of buyer-facing
 * search terms and platform aliases (UTV ↔ side-by-side ↔ SxS, "front end" ↔ frontend, brand /
 * fitment nicknames). The terms are written straight to `fm_search_keywords` via the product
 * resource action (a bulk attribute UPDATE, no full product save), so the native fulltext
 * indexer then makes them searchable and InstantSearch ranks them (see RelevanceConfig).
 *
 * Design guarantees:
 *  - Resumable: products that already carry keywords are skipped unless forced.
 *  - Best-effort: a batch that fails (API/transport/parse) is logged and skipped; the run
 *    continues, so one bad batch never aborts a 14k-product job.
 *  - Bounded: batch size + per-product term cap keep each prompt and each write small.
 */
class SearchKeywordGenerator
{
    private const XML_ENABLED = 'fastmagento/search/search_keywords_enabled';
    private const XML_SOURCE_ATTRIBUTES = 'fastmagento/search/keyword_source_attributes';
    private const XML_FACET_ATTRIBUTES = 'fastmagento/search/facet_attributes';

    private const DEFAULT_BATCH_SIZE = 25;
    private const MAX_TERMS_PER_PRODUCT = 30;
    private const MAX_TERM_LENGTH = 40;
    private const DESCRIPTION_SNIPPET = 400;
    private const MAX_TOKENS = 8000;

    public function __construct(
        private readonly ProductCollectionFactory $collectionFactory,
        private readonly ProductAction $productAction,
        private readonly AnthropicClient $client,
        private readonly AiConfig $aiConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriteLog $writeLog
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Run keyword generation over the catalogue.
     *
     * @param array{from?:int,to?:int,batch?:int,limit?:int,force?:bool,dry_run?:bool} $options
     * @param callable|null $progress fn(array $event): void — emitted per batch for CLI output.
     *        Event: {batch,ids,updated,skipped,failed,message}
     * @return array{processed:int,updated:int,skipped:int,failed:int,batches:int}
     * @throws \RuntimeException when preconditions (API key, attribute set) are unmet
     */
    public function run(array $options = [], ?callable $progress = null): array
    {
        if ($this->aiConfig->getApiKey() === '') {
            throw new \RuntimeException('No Claude API key is configured (FastMagento > AI Assistant).');
        }

        // Treat a missing/0 batch as "use the default" — the CLI passes 0 when --batch is omitted.
        $batchSize = (int) ($options['batch'] ?? 0);
        $batchSize = $batchSize > 0 ? min(100, $batchSize) : self::DEFAULT_BATCH_SIZE;
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $force = (bool) ($options['force'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $sourceAttributes = $this->getSourceAttributes();

        $stats = ['processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'batches' => 0];
        $buffer = [];

        foreach ($this->iterateProducts($options, $sourceAttributes) as $product) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }
            $stats['processed']++;

            if (!$force && trim((string) $product->getData(AddSearchKeywordsAttribute::ATTRIBUTE_CODE)) !== '') {
                $stats['skipped']++;
                continue;
            }

            $context = $this->buildContext($product, $sourceAttributes);
            if ($context === null) {
                $stats['skipped']++;
                continue;
            }
            $buffer[(int) $product->getId()] = $context;

            if (count($buffer) >= $batchSize) {
                $this->flushBatch($buffer, $dryRun, $stats, $progress);
                $buffer = [];
            }
        }

        if ($buffer) {
            $this->flushBatch($buffer, $dryRun, $stats, $progress);
        }

        return $stats;
    }

    /**
     * @param array{from?:int,to?:int} $options
     * @param string[] $sourceAttributes
     * @return \Generator<int, Product>
     */
    private function iterateProducts(array $options, array $sourceAttributes): \Generator
    {
        $select = array_values(array_unique(array_merge(
            ['name', 'short_description', 'description', AddSearchKeywordsAttribute::ATTRIBUTE_CODE],
            $sourceAttributes
        )));

        $pageSize = 200;
        $page = 1;
        do {
            $collection = $this->collectionFactory->create();
            $collection->addAttributeToSelect($select, 'left');
            $collection->addFieldToFilter('status', Status::STATUS_ENABLED);
            if (!empty($options['from'])) {
                $collection->addFieldToFilter('entity_id', ['gteq' => (int) $options['from']]);
            }
            if (!empty($options['to'])) {
                $collection->addFieldToFilter('entity_id', ['lteq' => (int) $options['to']]);
            }
            $collection->setOrder('entity_id', 'ASC');
            $collection->setPageSize($pageSize);
            $collection->setCurPage($page);

            $lastPage = $collection->getLastPageNumber();
            foreach ($collection as $product) {
                yield $product;
            }
            $collection->clear();
            $page++;
        } while ($page <= $lastPage);
    }

    /**
     * @param array<int, array<string, mixed>> $buffer id => context
     * @param array{processed:int,updated:int,skipped:int,failed:int,batches:int} $stats
     */
    private function flushBatch(array $buffer, bool $dryRun, array &$stats, ?callable $progress): void
    {
        $stats['batches']++;
        $ids = array_keys($buffer);

        try {
            $keywords = $dryRun ? [] : $this->generate($buffer);
        } catch (\Throwable $e) {
            $stats['failed'] += count($ids);
            $this->writeLog->writeErrorLog(
                '[FastMagento] keyword batch ' . $stats['batches'] . ' failed: ' . $e->getMessage()
            );
            if ($progress) {
                $progress([
                    'batch' => $stats['batches'], 'ids' => $ids,
                    'updated' => 0, 'skipped' => 0, 'failed' => count($ids),
                    'message' => 'FAILED: ' . $e->getMessage(),
                ]);
            }
            return;
        }

        $updated = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $terms = $this->cleanTerms($keywords[$id] ?? ($dryRun ? $this->previewTerms($buffer[$id]) : ''));
            if ($terms === '') {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $this->save($id, $terms);
            }
            $updated++;
        }
        $stats['updated'] += $updated;
        $stats['skipped'] += $skipped;

        if ($progress) {
            $progress([
                'batch' => $stats['batches'], 'ids' => $ids,
                'updated' => $updated, 'skipped' => $skipped, 'failed' => 0,
                'message' => $dryRun ? 'dry-run (no writes)' : 'ok',
            ]);
        }
    }

    /**
     * Send one batch to the model and return id => raw comma-separated keyword string.
     *
     * @param array<int, array<string, mixed>> $buffer id => context
     * @return array<int, string>
     */
    private function generate(array $buffer): array
    {
        $products = [];
        foreach ($buffer as $id => $context) {
            $products[] = ['id' => $id] + $context;
        }

        $result = $this->client->createStructured(
            $this->aiConfig->getApiKey(),
            $this->aiConfig->getModel(),
            $this->buildPrompt($products),
            $this->schema(),
            self::MAX_TOKENS
        );

        $map = [];
        foreach ((array) ($result['products'] ?? []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($buffer[$id])) {
                $map[$id] = (string) ($row['keywords'] ?? '');
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildContext(Product $product, array $sourceAttributes): ?array
    {
        $name = trim((string) $product->getData('name'));
        if ($name === '') {
            return null;
        }

        $attributes = [];
        foreach ($sourceAttributes as $code) {
            $label = $this->attributeText($product, $code);
            if ($label !== '') {
                $attributes[$code] = $label;
            }
        }

        $description = trim(strip_tags((string) (
            $product->getData('short_description') ?: $product->getData('description')
        )));
        $description = preg_replace('/\s+/', ' ', $description) ?? '';
        if (mb_strlen($description) > self::DESCRIPTION_SNIPPET) {
            $description = mb_substr($description, 0, self::DESCRIPTION_SNIPPET) . '…';
        }

        $context = ['name' => $name];
        if ($attributes) {
            $context['attributes'] = $attributes;
        }
        if ($description !== '') {
            $context['description'] = $description;
        }
        return $context;
    }

    private function attributeText(Product $product, string $code): string
    {
        $attribute = $product->getResource()->getAttribute($code);
        if ($attribute && $attribute->usesSource()) {
            try {
                $text = $product->getAttributeText($code);
            } catch (\Throwable $e) {
                $text = '';
            }
            if (is_array($text)) {
                $text = implode(', ', array_filter(array_map('strval', $text)));
            }
            return trim((string) $text);
        }
        $value = $product->getData($code);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function buildPrompt(array $products): string
    {
        $json = json_encode($products, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $max = self::MAX_TERMS_PER_PRODUCT;

        return <<<PROMPT
You are an e-commerce search expert building a hidden keyword/synonym layer for an off-road /
powersports parts and apparel catalogue on Magento. For each product below you get its id, name,
selected attribute labels, and a short description.

PRODUCTS:
{$json}

For EACH product, produce a compact, comma-separated list of the search terms a real shopper
would type to find THIS product but that may not appear verbatim in its name/description. Include:
- Platform / vehicle aliases and abbreviations (UTV ↔ side-by-side ↔ SxS ↔ side by side;
  ATV ↔ four-wheeler / quad; dirt bike ↔ moto).
- Part-type nicknames and everyday phrasings ("front end" ↔ frontend, "a-arm" ↔ control arm,
  light bar ↔ LED bar, skid plate ↔ belly pan).
- Fitment / brand / model nicknames actually used by buyers (e.g. RZR, Ranger, Maverick, Talon,
  Can-Am ↔ canam, Polaris, "turbo s").
- Common misspellings and singular/plural or hyphenation variants.

RULES:
- Terms describe what the product IS or FITS — never unrelated categories. Do not add "seats" to a
  suspension part just because both are UTV parts.
- All terms lowercase. Single words or short 2-3 word phrases. No duplicates. No term that merely
  repeats a word already in the product name.
- At most {$max} terms per product; fewer is fine. If a product genuinely needs no extra terms,
  return an empty string for it.
- Return one entry per product id given, keywords as a single comma-separated string.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'products' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'keywords' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'keywords'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['products'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Normalise a model keyword string: lowercase, split, trim, dedupe, drop over\long terms,
     * cap count, re-join comma-separated.
     */
    private function cleanTerms(string $raw): string
    {
        $terms = [];
        foreach (explode(',', mb_strtolower($raw)) as $term) {
            $term = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $term)) ?? '');
            if ($term === '' || mb_strlen($term) > self::MAX_TERM_LENGTH) {
                continue;
            }
            if (!in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }
        return implode(', ', array_slice($terms, 0, self::MAX_TERMS_PER_PRODUCT));
    }

    /**
     * Deterministic preview terms for --dry-run (no API call): just the source attribute labels.
     *
     * @param array<string, mixed> $context
     */
    private function previewTerms(array $context): string
    {
        return implode(', ', array_map('strval', array_values((array) ($context['attributes'] ?? []))));
    }

    private function save(int $id, string $terms): void
    {
        // Bulk attribute UPDATE (no full product save) at global scope — attribute is SCOPE_GLOBAL.
        $this->productAction->updateAttributes(
            [$id],
            [AddSearchKeywordsAttribute::ATTRIBUTE_CODE => $terms],
            0
        );
    }

    /**
     * Context attribute codes fed to the model: the dedicated setting, or the facet attributes
     * as a sensible fallback (they already describe the product: part_type, fitment, etc.).
     *
     * @return string[]
     */
    private function getSourceAttributes(): array
    {
        $configured = (string) $this->scopeConfig->getValue(self::XML_SOURCE_ATTRIBUTES, ScopeInterface::SCOPE_STORE);
        if (trim($configured) === '') {
            $configured = (string) $this->scopeConfig->getValue(self::XML_FACET_ATTRIBUTES, ScopeInterface::SCOPE_STORE);
        }
        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
