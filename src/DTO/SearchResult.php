<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\DTO;

/**
 * Résultat normalisé d'une recherche Meilisearch.
 */
final readonly class SearchResult
{
    public function __construct(
        /** Documents retournés par Meilisearch. Chaque hit contient au minimum 'ID'. */
        public array $hits,

        /** Nombre total de résultats (toutes pages confondues). */
        public int $total,

        /** Nombre total de pages. */
        public int $totalPages,

        /** Page courante. */
        public int $currentPage,

        /**
         * Distribution des facettes (uniquement si $includeFacets = true).
         * Format : ['slug-terme' => nombre-de-résultats]
         * Ex: ['finance' => 12, 'industrie' => 8]
         */
        public array $facetDistribution = [],
    ) {}
}
