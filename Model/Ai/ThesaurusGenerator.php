<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Ai;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Builds a search thesaurus for THIS store's catalogue: it reads the real vocabulary the store
 * sells (select/multiselect/swatch attribute option labels + category names), asks the model to
 * map common shopper words to those terms, then merges the result into the
 * fastmagento/search/synonyms config (which drives both fulltext relevance and swatch
 * pre-selection). "Generate from your website" — no hand-curation.
 */
class ThesaurusGenerator
{
    private const XML_SYNONYMS = 'fastmagento/search/synonyms';
    private const MAX_OPTIONS_PER_ATTRIBUTE = 200;
    private const MAX_CATEGORIES = 300;
    private const MAX_GROUPS = 120;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly AnthropicClient $client,
        private readonly AiConfig $aiConfig
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

        $groups = $this->cleanGroups($result['groups'] ?? []);
        if (!$groups) {
            throw new \RuntimeException('The model did not return any usable synonym groups.');
        }

        return $this->merge($groups) + ['terms_scanned' => $termCount];
    }

    /**
     * @return array{attributes:array<string,string[]>, categories:string[]}
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

        return ['attributes' => $attributes, 'categories' => $categories];
    }

    /**
     * @param array{attributes:array<string,string[]>, categories:string[]} $vocabulary
     */
    private function countTerms(array $vocabulary): int
    {
        $count = count($vocabulary['categories']);
        foreach ($vocabulary['attributes'] as $labels) {
            $count += count($labels);
        }
        return $count;
    }

    /**
     * @param array{attributes:array<string,string[]>, categories:string[]} $vocabulary
     */
    private function buildPrompt(array $vocabulary): string
    {
        $vocabJson = json_encode($vocabulary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $maxGroups = self::MAX_GROUPS;

        return <<<PROMPT
You are an e-commerce search relevance expert building a synonym thesaurus for a Magento storefront.

Below is the store's actual searchable vocabulary: option labels grouped by attribute (colours, sizes, materials, styles, fitment, etc.) and category names. These are the real terms shoppers will find in the catalogue.

VOCABULARY:
{$vocabJson}

Produce synonym groups that map the words REAL SHOPPERS TYPE to the store's actual terms, so a search for a shopper's word also finds the catalogue term. Focus on:
- Colour families and alternate colour names (e.g. a "Merlot" option -> burgundy, wine, maroon).
- Size abbreviations and spelled-out forms (S/small, XL/extra large, numeric/lettered sizes).
- Materials and their common variants and misspellings.
- Product types / categories and their everyday or regional names and abbreviations.
- Common misspellings and British/American spelling pairs.

RULES:
- Each group is a list of interchangeable terms. Every group MUST contain at least one term that appears verbatim (case-insensitive) in the vocabulary above — never invent groups of words the store doesn't use.
- All terms lowercase. Prefer single words; short two-word phrases are allowed.
- NEVER put two DIFFERENT real option labels from the same attribute in one group (e.g. do not make "Red" and "Pink" synonyms) — that would make the store's own distinct options collide.
- Do not restate the obvious identity (a term is always a synonym of itself); every group needs at least two DIFFERENT terms.
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

    /**
     * Normalise the model's groups: lowercase, trim, dedupe within a group, drop groups with
     * fewer than two distinct terms.
     *
     * @param mixed $rawGroups
     * @return string[][]
     */
    private function cleanGroups($rawGroups): array
    {
        $groups = [];
        foreach ((array) $rawGroups as $group) {
            $terms = [];
            foreach ((array) $group as $term) {
                // Commas and newlines are the group/line separators in the synonyms config, so
                // a term containing either would corrupt the round-trip — strip them. Bound the
                // length as a guard against a pathological model response.
                $term = trim(mb_strtolower(str_replace([',', "\r", "\n"], ' ', (string) $term)));
                $term = trim(preg_replace('/\s+/', ' ', $term) ?? '');
                if ($term === '' || mb_strlen($term) > 40) {
                    continue;
                }
                if (!in_array($term, $terms, true)) {
                    $terms[] = $term;
                }
            }
            if (count($terms) >= 2) {
                $groups[] = $terms;
            }
        }
        return array_slice($groups, 0, self::MAX_GROUPS);
    }

    /**
     * Merge new groups into the existing synonyms config without clobbering hand-curated lines:
     * a new group that shares a term with an existing group unions into it; otherwise it's
     * appended. Saves the result and reinitialises config so it takes effect immediately.
     *
     * @param string[][] $newGroups
     * @return array{added:int, merged:int, total:int, preview:string[]}
     */
    private function merge(array $newGroups): array
    {
        $existing = $this->parseGroups((string) $this->scopeConfig->getValue(self::XML_SYNONYMS));
        $added = 0;
        $merged = 0;

        foreach ($newGroups as $group) {
            $targetIndex = null;
            foreach ($existing as $i => $existingGroup) {
                if (array_intersect($group, $existingGroup)) {
                    $targetIndex = $i;
                    break;
                }
            }

            if ($targetIndex === null) {
                $existing[] = $group;
                $added++;
                continue;
            }

            $before = $existing[$targetIndex];
            $existing[$targetIndex] = array_values(array_unique(array_merge($before, $group)));
            if (count($existing[$targetIndex]) > count($before)) {
                $merged++;
            }
        }

        $text = implode("\n", array_map(static fn ($g) => implode(', ', $g), $existing));
        $this->configWriter->save(self::XML_SYNONYMS, $text);
        $this->reinitableConfig->reinit();

        return [
            'added' => $added,
            'merged' => $merged,
            'total' => count($existing),
            'preview' => array_map(
                static fn ($g) => implode(', ', $g),
                array_slice($newGroups, 0, 12)
            ),
        ];
    }

    /**
     * Parse a synonyms textarea value into groups of terms.
     *
     * @return string[][]
     */
    private function parseGroups(string $raw): array
    {
        $groups = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $terms = array_values(array_unique(array_filter(array_map(
                static fn ($t) => trim(mb_strtolower($t)),
                explode(',', $line)
            ))));
            if (count($terms) >= 2) {
                $groups[] = $terms;
            }
        }
        return $groups;
    }
}
