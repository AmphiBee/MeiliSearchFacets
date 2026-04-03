<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Service;

use AmphiBee\MeilisearchFacets\Client\MeilisearchClient;
use AmphiBee\MeilisearchFacets\Config\SearchConfigInterface;
use AmphiBee\MeilisearchFacets\DTO\SearchRequest;
use AmphiBee\MeilisearchFacets\DTO\SearchResult;

/**
 * Orchestre les requêtes Meilisearch pour un listing facetté.
 */
class FacetsSearchService
{
    public function __construct(private readonly MeilisearchClient $client) {}

    /**
     * Effectue la recherche principale et retourne les résultats normalisés.
     *
     * @param  bool  $includeFacets  Si true, inclut la distribution des facettes dans le résultat.
     */
    public function search(
        SearchConfigInterface $config,
        SearchRequest $request,
        bool $includeFacets = false,
    ): SearchResult {
        $filters = $this->buildFilters($config, $request);

        $hitsPerPage = $request->hitsPerPage ?? $config->getHitsPerPage();

        $params = [
            'q'           => $request->query,
            'hitsPerPage' => $hitsPerPage,
            'page'        => $request->page,
            'filter'      => implode(' AND ', $filters),
        ];

        if (! empty($request->query)) {
            $params['matchingStrategy'] = config('meilisearch-facets.search.matching_strategy', 'last');
        }

        $sort = ! empty($request->sort) ? $request->sort : $config->getDefaultSort();
        if (! empty($sort)) {
            $params['sort'] = $sort;
        }

        if ($includeFacets) {
            $params['facets'] = ['terms.slug'];
        }

        $data = $this->client->search($config->getIndex(), $params);

        $total = $data['totalHits'] ?? ($data['estimatedTotalHits'] ?? 0);

        return new SearchResult(
            hits: $data['hits'] ?? [],
            total: $total,
            totalPages: $data['totalPages'] ?? (int) ceil($total / $hitsPerPage),
            currentPage: $request->page,
            facetDistribution: $data['facetDistribution']['terms.slug'] ?? [],
        );
    }

    /**
     * Détermine quelles plages d'un groupe numérique ont des résultats,
     * en ignorant le filtre de ce groupe pour la requête de vérification.
     *
     * Utilise le multi-search Meilisearch pour n'effectuer qu'un seul appel HTTP.
     *
     * @param  string  $rangeGroup  Clé du groupe (ex: 'price_range')
     * @param  SearchRequest  $requestWithoutGroup  Requête sans le filtre du groupe concerné
     * @return array<string, bool>  ['cle-plage' => true/false]
     */
    public function getAvailableRanges(
        SearchConfigInterface $config,
        string $rangeGroup,
        SearchRequest $requestWithoutGroup,
    ): array {
        $rangeGroups = $config->getNumericRangeGroups();

        if (! isset($rangeGroups[$rangeGroup])) {
            return [];
        }

        $ranges = $rangeGroups[$rangeGroup];
        $baseFilters = $this->buildFilters($config, $requestWithoutGroup);
        $baseFilter = implode(' AND ', $baseFilters);

        $queries = [];
        foreach ($ranges as $range) {
            $query = [
                'indexUid'    => $config->getIndex(),
                'q'           => $requestWithoutGroup->query,
                'filter'      => $baseFilter . ' AND ' . $range->toMeilisearchFilter(),
                'hitsPerPage' => 1,
                'page'        => 1,
            ];

            if (! empty($requestWithoutGroup->query)) {
                $query['matchingStrategy'] = config('meilisearch-facets.search.matching_strategy', 'last');
            }

            $queries[] = $query;
        }

        $results = $this->client->multiSearch($queries);

        $available = [];
        foreach ($ranges as $i => $range) {
            $totalHits = $results[$i]['totalHits'] ?? ($results[$i]['estimatedTotalHits'] ?? 0);
            $available[$range->key] = $totalHits > 0;
        }

        return $available;
    }

    /**
     * Mappe des slugs de termes vers leurs taxonomies WordPress.
     * Utilisé pour construire la réponse availableFacets depuis facetDistribution.
     *
     * @param  string[]  $slugs  Slugs issus de facetDistribution de Meilisearch
     * @return array<string, string[]>  ['nom-taxonomie' => ['slug1', 'slug2']]
     */
    public function mapSlugsToTaxonomies(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($slugs), '%s'));
        $query = $wpdb->prepare(
            "SELECT t.slug, tt.taxonomy
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE t.slug IN ({$placeholders})",
            ...$slugs
        );

        $results = $wpdb->get_results($query);

        $mapped = [];
        foreach ($results as $row) {
            $mapped[$row->taxonomy][] = $row->slug;
        }

        return $mapped;
    }

    /**
     * Construit le tableau de filtres Meilisearch à partir d'un SearchRequest et d'une config.
     *
     * @return string[]
     */
    private function buildFilters(SearchConfigInterface $config, SearchRequest $request): array
    {
        $postType = addslashes($config->getPostType());
        $filters = [
            "post_type = \"{$postType}\"",
            'post_status = "publish"',
        ];

        // Filtres taxonomiques
        foreach ($request->taxonomyFilters as $taxonomy => $slugs) {
            if (empty($slugs)) {
                continue;
            }

            $taxonomy = addslashes($taxonomy);

            if (is_array($slugs)) {
                $slugList  = implode("', '", array_map('addslashes', $slugs));
                $filters[] = "(terms.taxonomy = '{$taxonomy}' AND terms.slug IN ['{$slugList}'])";
            } else {
                $slug      = addslashes($slugs);
                $filters[] = "(terms.taxonomy = '{$taxonomy}' AND terms.slug = '{$slug}')";
            }
        }

        // Filtres custom (meta, booléens, etc.)
        foreach ($request->customFilters as $filter) {
            $filters[] = $filter;
        }

        // Filtres numériques
        $rangeGroups = $config->getNumericRangeGroups();
        foreach ($request->numericFilters as $groupKey => $selectedKeys) {
            if (empty($selectedKeys) || ! isset($rangeGroups[$groupKey])) {
                continue;
            }

            $selectedKeys   = (array) $selectedKeys;
            $rangeFilters = [];

            foreach ($rangeGroups[$groupKey] as $range) {
                if (in_array($range->key, $selectedKeys, strict: true)) {
                    $rangeFilters[] = $range->toMeilisearchFilter();
                }
            }

            if (! empty($rangeFilters)) {
                $filters[] = '(' . implode(' OR ', $rangeFilters) . ')';
            }
        }

        return $filters;
    }
}
