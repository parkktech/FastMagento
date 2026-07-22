<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Single owner of the synonyms config (`fastmagento/search/synonyms`): parse, clean, and
 * merge synonym groups without clobbering hand-curated lines. Shared by the starter-thesaurus
 * install patch, the AI thesaurus generator, and any future importer, so "how synonyms are
 * stored and merged" lives in exactly one place.
 *
 * Merge rule: a new group that shares any term with an existing group unions into it; otherwise
 * it is appended. Terms are lower-cased, trimmed, comma/newline-stripped (those are the storage
 * separators), length-bounded, and de-duped; groups with fewer than two distinct terms are dropped.
 */
class SynonymImporter
{
    public const XML_SYNONYMS = 'fastmagento/search/synonyms';

    private const MAX_TERM_LENGTH = 40;
    private const MAX_GROUPS = 400;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    /**
     * Merge new groups into the stored synonyms and persist. Returns import stats.
     *
     * @param string[][] $newGroups
     * @return array{added:int, merged:int, total:int, preview:string[]}
     */
    public function merge(array $newGroups): array
    {
        $newGroups = $this->clean($newGroups);
        $existing = $this->parse((string) $this->scopeConfig->getValue(self::XML_SYNONYMS));
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

        $existing = array_slice($existing, 0, self::MAX_GROUPS);
        $this->configWriter->save(self::XML_SYNONYMS, $this->render($existing));
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
    public function parse(string $raw): array
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

    /**
     * Normalise model/importer groups: lowercase, trim, strip separators, bound length, dedupe;
     * drop groups with fewer than two distinct terms.
     *
     * @param mixed $rawGroups
     * @return string[][]
     */
    public function clean($rawGroups): array
    {
        $groups = [];
        foreach ((array) $rawGroups as $group) {
            $terms = [];
            foreach ((array) $group as $term) {
                $term = trim(mb_strtolower(str_replace([',', "\r", "\n"], ' ', (string) $term)));
                $term = trim((string) preg_replace('/\s+/', ' ', $term));
                if ($term === '' || mb_strlen($term) > self::MAX_TERM_LENGTH) {
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
        return $groups;
    }

    /**
     * @param string[][] $groups
     */
    private function render(array $groups): string
    {
        return implode("\n", array_map(static fn ($g) => implode(', ', $g), $groups));
    }
}
