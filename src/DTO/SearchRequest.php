<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\DTO;

/**
 * Paramètres d'une requête de recherche facettée.
 */
final readonly class SearchRequest
{
    public function __construct(
        /** Texte de recherche libre. */
        public string $query = '',

        /**
         * Filtres taxonomiques actifs.
         * Format : ['nom-taxonomie' => ['slug1', 'slug2']]
         * Ex: ['activity-sector' => ['finance', 'industrie']]
         */
        public array $taxonomyFilters = [],

        /**
         * Filtres numériques actifs, indexés par nom de groupe.
         * Format : ['nom_du_groupe' => ['cle-plage1', 'cle-plage2']]
         * Ex: ['price_range' => ['1000-2000', '4000+']]
         */
        public array $numericFilters = [],

        /** Page courante (commence à 1). */
        public int $page = 1,

        /**
         * Règles de tri Meilisearch.
         * Ex: ['metas.price:asc'] ou ['post_title:desc']
         */
        public array $sort = [],

        /**
         * Filtres Meilisearch supplémentaires issus de getCustomFilters().
         * Chaînes prêtes à être ajoutées directement dans la clause filter.
         * Ex: ['multisiteProject = "1"']
         */
        public array $customFilters = [],

        /**
         * Nombre de résultats par page, défini par le bloc Gutenberg.
         * Null = utilise la valeur de SearchConfigInterface::getHitsPerPage().
         */
        public ?int $hitsPerPage = null,
    ) {}
}
