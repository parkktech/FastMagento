<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\OpenSearch;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * Request-scoped read primitive for the category serving layer.
 *
 * The whole category tree (hundreds of docs) is pulled from OpenSearch in ONE search on
 * first access and kept in memory for the request, then exposed as tree lookups
 * (by id, children by parent, request path). Every storefront category read path — menu,
 * breadcrumbs, layered nav — is served from these maps instead of catalog_category_entity*.
 *
 * A single failed/empty load flips $available to false so callers transparently fall back
 * to the native EAV path (OS-down resilience, matching the product read path).
 */
class CategoryDataProvider
{
    /** OS default max_result_window; the catalog has far fewer categories. */
    private const MAX_CATEGORIES = 10000;

    /** @var array<int, array<string, mixed>>|null entity_id => doc */
    private ?array $byId = null;

    /** @var array<int, int[]>|null parent_id => child entity_ids (position-sorted) */
    private ?array $childrenByParent = null;

    private bool $available = false;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * True when the tree loaded from OpenSearch and has at least one category.
     */
    public function isAvailable(): bool
    {
        $this->load();
        return $this->available;
    }

    /**
     * @return array<string, mixed>|null The category doc, or null if not present.
     */
    public function getById(int $entityId): ?array
    {
        $this->load();
        return $this->byId[$entityId] ?? null;
    }

    /**
     * Direct child ids of a category, position-sorted.
     *
     * @return int[]
     */
    public function getChildIds(int $parentId): array
    {
        $this->load();
        return $this->childrenByParent[$parentId] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>> All docs keyed by entity_id.
     */
    public function getAll(): array
    {
        $this->load();
        return $this->byId ?? [];
    }

    /**
     * Canonical store request path for a category (empty string if unknown).
     */
    public function getRequestPath(int $entityId): string
    {
        $doc = $this->getById($entityId);
        return (string) ($doc['request_path'] ?? '');
    }

    /**
     * Lazily load and index the whole tree from OpenSearch once per request.
     */
    private function load(): void
    {
        if ($this->byId !== null) {
            return;
        }
        $this->byId = [];
        $this->childrenByParent = [];

        try {
            $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
            $response = $client->getOpenSearchClient()->search([
                'index' => $this->openSearchConfig->getCategoryIndexName(),
                'body' => [
                    'size' => self::MAX_CATEGORIES,
                    'query' => ['match_all' => (object) []],
                    'sort' => [['position' => 'asc'], ['entity_id' => 'asc']],
                ],
            ]);

            $hits = $response['hits']['hits'] ?? [];
            foreach ($hits as $hit) {
                $doc = $hit['_source'] ?? null;
                if (!$doc || !isset($doc['entity_id'])) {
                    continue;
                }
                $id = (int) $doc['entity_id'];
                $this->byId[$id] = $doc;
                $this->childrenByParent[(int) ($doc['parent_id'] ?? 0)][] = $id;
            }

            $this->available = !empty($this->byId);
        } catch (\Throwable $e) {
            // OS unavailable / index missing → fall back to native EAV everywhere.
            $this->available = false;
            $this->writeLog->writeErrorLog('[FastMagento] category tree load failed: ' . $e->getMessage());
        }
    }
}
