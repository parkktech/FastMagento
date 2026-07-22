<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Ai;

use Magento\Framework\App\ResourceConnection;
use ParkkTech\FastMagento\Model\Search\SynonymImporter;

/**
 * Builds a search thesaurus for THIS store's catalogue by scraping the real vocabulary the store
 * sells — select/multiselect/swatch attribute option labels, category names, AND a sample of
 * product names — then asking the model to (a) map common shopper words to those terms and
 * (b) surface grammatical/compound variants the copy actually uses (front end ↔ frontend,
 * back end ↔ rear end, a-arm ↔ a arm, hyphenation/spacing/plural pairs). The result merges into
 * the fastmagento/search/synonyms config (which drives fulltext relevance and swatch
 * pre-selection). "Generate from your website" — no hand-curation.
 */
class ThesaurusGenerator
{
    private const MAX_OPTIONS_PER_ATTRIBUTE = 200;
    private const MAX_CATEGORIES = 300;
    private const MAX_PRODUCT_SAMPLES = 400;
    private const MAX_GROUPS = 120;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AnthropicClient $client,
        private readonly AiConfig $aiConfig,
        private readonly SynonymImporter $synonymImporter
    ) {
    }

    /**
     * Generate synonym groups from the catalogue and merge them into the synonyms config.
     *
     * @return array{added:int, merged:int, total:int, preview:string[], terms_scanned:int}
     * @throws \RuntimeException
     */
    public function generateAndImport(): array
    {
        $vocabulary = $this->collectVocabulary();
        $termCount = $this->countTerms($vocabulary);
        if ($termCount === 0) {
            throw new \RuntimeException('No catalogue vocabulary found to build a thesaurus from.');
        }

        $result = $this->client->createStructured(
            $this->aiConfig->getApiKey(),
            $this->aiConfig->getModel(),
            $this->buildPrompt($vocabulary),
            $this->schema(),
            8000
        );

        $groups = $this->synonymImporter->clean($result['groups'] ?? []);
        if (!$groups) {
            throw new \RuntimeException('The model did not return any usable synonym groups.');
        }

        return $this->synonymImporter->merge($groups) + ['terms_scanned' => $termCount];
    }

    /**
     * @return array{attributes:array<string,string[]>, categories:string[], products:string[]}
     */
    private function collectVocabulary(): array
    {
        $connection = $this->resource->getConnection();
        $budget = $this->aiConfig->getMaxTerms();

        // Attributes with an option list (colours, sizes, materials, styles, fitment, ...).
        $attrSelect = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id', 'attribute_code', 'frontend_label'])
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = a.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', 'catalog_product')
            ->where('a.frontend_input IN (?)', ['select', 'multiselect'])
            ->where('a.is_user_defined = ?', 1);

        $attrMeta = [];
        foreach ($connection->fetchAll($attrSelect) as $row) {
            $attrMeta[(int) $row['attribute_id']] = trim((string) ($row['frontend_label'] ?: $row['attribute_code']));
        }

        $attributes = [];
        $used = 0;
        if ($attrMeta) {
            $optSelect = $connection->select()
                ->from(['o' => $this->resource->getTableName('eav_attribute_option')], ['attribute_id'])
                ->join(
                    ['v' => $this->resource->getTableName('eav_attribute_option_value')],
                    'v.option_id = o.option_id AND v.store_id = 0',
                    ['label' => 'value']
                )
                ->where('o.attribute_id IN (?)', array_keys($attrMeta))
                ->order('o.attribute_id')
                ->order('o.sort_order');

            foreach ($connection->fetchAll($optSelect) as $row) {
                if ($used >= $budget) {
                    break;
                }
                $label = trim((string) $row['label']);
                $name = $attrMeta[(int) $row['attribute_id']] ?? '';
                if ($label === '' || $name === '') {
                    continue;
                }
                if (count($attributes[$name] ?? []) >= self::MAX_OPTIONS_PER_ATTRIBUTE) {
                    continue;
                }
                if (!isset($attributes[$name]) || !in_array($label, $attributes[$name], true)) {
                    $attributes[$name][] = $label;
                    $used++;
                }
            }
        }

        // Category names (product-type vocabulary).
        $categories = [];
        $catSelect = $connection->select()
            ->from(['v' => $this->resource->getTableName('catalog_category_entity_varchar')], ['value'])
            ->join(
                ['a' => $this->resource->getTableName('eav_attribute')],
                'a.attribute_id = v.attribute_id',
                []
            )
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = a.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', 'catalog_category')
            ->where('a.attribute_code = ?', 'name')
            ->where('v.store_id = ?', 0)
            ->limit(self::MAX_CATEGORIES);

        foreach ($connection->fetchCol($catSelect) as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $categories, true) && $used < $budget) {
                $categories[] = $name;
                $used++;
            }
        }

        // Product-name sample — the raw copy where grammatical/compound variants actually live
        // ("front end", "rock racer", hyphenated part names). Prioritise multi-word / hyphenated
        // names, which are the ones that carry spacing/compound complexities worth mapping.
        $products = $this->collectProductNames($connection, min($budget - $used, self::MAX_PRODUCT_SAMPLES));

        return ['attributes' => $attributes, 'categories' => $categories, 'products' => $products];
    }

    /**
     * A bounded sample of product names, multi-word / hyphenated first (those hold the
     * grammatical complexities the tool exists to find), then filling with the rest.
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @return string[]
     */
    private function collectProductNames($connection, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $nameAttr = $connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(['et' => $this->resource->getTableName('eav_entity_type')], 'et.entity_type_id = a.entity_type_id', [])
                ->where('et.entity_type_code = ?', 'catalog_product')
                ->where('a.attribute_code = ?', 'name')
        );
        if (!$nameAttr) {
            return [];
        }

        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName('catalog_product_entity_varchar')], ['value'])
            ->where('v.attribute_id = ?', (int) $nameAttr)
            ->where('v.store_id = ?', 0)
            ->where('v.value IS NOT NULL')
            // multi-word / hyphenated names first (REGEXP: a space or hyphen present)
            ->order(new \Zend_Db_Expr("(v.value REGEXP '[ -]') DESC"))
            ->limit($limit * 3);

        $names = [];
        foreach ($connection->fetchCol($select) as $name) {
            $name = trim((string) $name);
            if ($name === '' || isset($names[mb_strtolower($name)])) {
                continue;
            }
            $names[mb_strtolower($name)] = $name;
            if (count($names) >= $limit) {
                break;
            }
        }
        return array_values($names);
    }

    /**
     * @param array{attributes:array<string,string[]>, categories:string[], products:string[]} $vocabulary
     */
    private function countTerms(array $vocabulary): int
    {
        $count = count($vocabulary['categories']) + count($vocabulary['products']);
        foreach ($vocabulary['attributes'] as $labels) {
            $count += count($labels);
        }
        return $count;
    }

    /**
     * @param array{attributes:array<string,string[]>, categories:string[], products:string[]} $vocabulary
     */
    private function buildPrompt(array $vocabulary): string
    {
        $vocabJson = json_encode($vocabulary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $maxGroups = self::MAX_GROUPS;

        return <<<PROMPT
You are an e-commerce search relevance expert building a synonym thesaurus for a Magento storefront.

Below is the store's actual searchable vocabulary scraped from its own content: option labels grouped by attribute (colours, sizes, materials, styles, fitment, etc.), category names, and a sample of real product names. These are the exact terms and phrasings the catalogue uses.

VOCABULARY:
{$vocabJson}

Produce synonym groups that map the words REAL SHOPPERS TYPE to the store's actual terms, so a search for a shopper's word also finds the catalogue term. Cover:
- GRAMMATICAL / COMPOUND VARIANTS of multi-word phrases the product names actually use — this is the most valuable output. A shopper may type a phrase spaced, joined, or hyphenated differently than the copy. If a product name contains "front end", emit ["front end","frontend","front-end"]; likewise "back end"->"backend"/"rear end", "a-arm"->"a arm", "light bar"->"lightbar", "t-shirt"->"tshirt"/"tee". Read the product names and generate these pairs for every multi-word / hyphenated phrase that a buyer would plausibly type another way.
- Colour families and alternate colour names (a "Merlot" option -> burgundy, wine, maroon).
- Size abbreviations and spelled-out forms (S/small, XL/extra large).
- Materials, product-type nicknames, regional names, abbreviations, common misspellings, and British/American spelling pairs.

RULES:
- Each group is a list of interchangeable terms. Every group MUST contain at least one term that appears verbatim (case-insensitive) in the vocabulary above — never invent groups of words the store doesn't use.
- CRITICAL — keep every term DISTINCTIVE. NEVER build a group around a common word that appears widely across unrelated products (e.g. "side", "front", "kit", "set", "black", "pro"). A group like ["side by side","utv"] is HARMFUL because matching the common word "side" then pulls in every "...side" product. If a useful buyer phrase contains a common word, DROP it from the thesaurus (it is handled separately per-product); only emit groups whose every term is specific enough to identify the product type.
- All terms lowercase. Single words or short 2-3 word phrases.
- NEVER put two DIFFERENT real option labels from the same attribute in one group (do not make "Red" and "Pink" synonyms) — that collides the store's own distinct options.
- Every group needs at least two DIFFERENT terms; do not restate a term as its own synonym.
- Return at most {$maxGroups} of the highest-value groups.
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
                'groups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['groups'],
            'additionalProperties' => false,
        ];
    }
}
