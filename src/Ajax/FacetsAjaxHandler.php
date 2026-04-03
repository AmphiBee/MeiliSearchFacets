<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Ajax;

use AmphiBee\MeilisearchFacets\Config\SearchConfigInterface;
use AmphiBee\MeilisearchFacets\DTO\SearchRequest;
use AmphiBee\MeilisearchFacets\Service\FacetsSearchService;

/**
 * Handler AJAX WordPress générique pour un listing facetté.
 *
 * Enregistrement dans un ServiceProvider ou une Hook Pollora :
 *   FacetsAjaxHandler::register(new ReferenceSearchConfig());
 */
class FacetsAjaxHandler
{
    public function __construct(
        private readonly SearchConfigInterface $config,
        private readonly FacetsSearchService $service,
    ) {}

    /**
     * Enregistre les actions AJAX WordPress pour une config donnée.
     * À appeler depuis un ServiceProvider ou un hook Pollora (après 'init').
     */
    public static function register(SearchConfigInterface $config): void
    {
        $handler = new self($config, app(FacetsSearchService::class));
        $action  = $config->getAjaxAction();

        add_action("wp_ajax_{$action}", [$handler, 'handle']);
        add_action("wp_ajax_nopriv_{$action}", [$handler, 'handle']);
    }

    /**
     * Traite la requête AJAX et retourne le JSON de réponse.
     *
     * Paramètres POST attendus :
     *   - facets (array)       : filtres actifs (taxonomies, plages, tri)
     *   - extra_datas (array)  : page, search, facet_location
     */
    public function handle(): void
    {
        $rawFacets = $_POST['facets'] ?? [];
        $facets    = $this->sanitizeFacets($rawFacets);

        $page          = max(1, (int) ($_POST['extra_datas']['page'] ?? 1));
        $searchQuery   = sanitize_text_field($_POST['extra_datas']['search'] ?? '');
        $facetLocation = sanitize_text_field($_POST['extra_datas']['facet_location'] ?? 'grid');
        $hitsPerPage   = isset($_POST['extra_datas']['hitsPerPage'])
            ? max(1, (int) $_POST['extra_datas']['hitsPerPage'])
            : null;

        [$taxonomyFilters, $numericFilters, $sort, $unknownFacets] = $this->parseFacets($facets);

        $request = new SearchRequest(
            query: $searchQuery,
            taxonomyFilters: $taxonomyFilters,
            numericFilters: $numericFilters,
            page: $page,
            sort: $sort,
            customFilters: $this->config->getCustomFilters($unknownFacets),
            hitsPerPage: $hitsPerPage,
        );

        try {
            $result = $this->service->search($this->config, $request, includeFacets: true);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        // Mapper les slugs de facettes vers leurs taxonomies
        $availableFacets = [];
        if (! empty($result->facetDistribution)) {
            $availableFacets = $this->service->mapSlugsToTaxonomies(array_keys($result->facetDistribution));
        }

        // Calculer les plages numériques disponibles pour chaque groupe
        $availableRanges = [];
        foreach (array_keys($this->config->getNumericRangeGroups()) as $groupKey) {
            $requestWithoutGroup = new SearchRequest(
                query: $searchQuery,
                taxonomyFilters: $taxonomyFilters,
                numericFilters: array_diff_key($numericFilters, [$groupKey => null]),
                page: 1,
                sort: $sort,
            );
            $availableRanges[$groupKey] = $this->service->getAvailableRanges(
                $this->config,
                $groupKey,
                $requestWithoutGroup
            );
        }

        // Mode "drawer" : retourne uniquement le compteur + facettes disponibles
        if ($facetLocation === 'drawer_filters') {
            wp_send_json_success([
                'results'        => $result->total,
                'availableFacets' => $availableFacets,
                'availableRanges' => $availableRanges,
            ]);
        }

        // Mode "grille" : retourne le HTML de la grille + pagination
        $grid       = '';
        $pagination = '';

        if (! empty($result->hits)) {
            ob_start();
            foreach ($result->hits as $hit) {
                echo $this->config->renderHit($hit);
            }
            $grid = ob_get_clean();

            $link     = '';
            $referrer = parse_url($_SERVER['HTTP_REFERER'] ?? '');
            if (is_array($referrer) && ! empty($referrer['host'])) {
                $scheme = $referrer['scheme'] ?? 'https';
                $host   = $referrer['host'];
                $path   = $referrer['path'] ?? '/';
                $link   = add_query_arg($facets, "{$scheme}://{$host}{$path}");
            }

            $pagination = $this->config->renderPagination($result->totalPages, $result->currentPage, $link);
        }

        wp_send_json_success([
            'grid'            => $grid,
            'pagination'      => $pagination,
            'total'           => $result->total,
            'availableFacets' => $availableFacets,
            'availableRanges' => $availableRanges,
        ]);
    }

    /**
     * Sanitize récursive des valeurs de facettes.
     */
    private function sanitizeFacets(array $rawFacets): array
    {
        return array_map(static function ($value) {
            if (is_array($value)) {
                return array_map('sanitize_text_field', $value);
            }

            return sanitize_text_field($value);
        }, $rawFacets);
    }

    /**
     * Extrait les filtres taxonomiques, numériques et le tri depuis le tableau de facettes POST.
     *
     * Convention de nommage :
     *   - Taxonomies  : préfixe '_search_'   → ex: '_search_activity-sector'
     *   - Plages num. : suffixe '_range'      → ex: 'price_range'
     *   - Tri         : clé 'order'           → ex: 'asc', 'desc', 'price'
     *
     * @return array{0: array, 1: array, 2: array}  [taxonomyFilters, numericFilters, sort]
     */
    private function parseFacets(array $facets): array
    {
        $taxonomyFilters = [];
        $numericFilters  = [];
        $unknownFacets   = [];
        $sort            = $this->config->getDefaultSort();

        foreach ($facets as $key => $value) {
            if (empty($value)) {
                continue;
            }

            if (str_contains($key, '_search_')) {
                $taxonomy = str_replace('_search_', '', $key);
                $taxonomyFilters[$taxonomy] = is_array($value) ? $value : [$value];
                continue;
            }

            if (str_ends_with($key, '_range')) {
                $numericFilters[$key] = is_array($value) ? $value : [$value];
                continue;
            }

            if ($key === 'order') {
                $sort = $this->resolveSort($value);
                continue;
            }

            $unknownFacets[$key] = $value;
        }

        return [$taxonomyFilters, $numericFilters, $sort, $unknownFacets];
    }

    /**
     * Convertit la valeur du champ 'order' en règles de tri Meilisearch.
     * Surcharger renderHit() ou étendre FacetsAjaxHandler pour des tris personnalisés.
     */
    private function resolveSort(string $order): array
    {
        return match ($order) {
            'asc'  => ['post_title:asc'],
            'desc' => ['post_title:desc'],
            default => $this->config->getDefaultSort(),
        };
    }
}
