<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Config;

use AmphiBee\MeilisearchFacets\DTO\NumericRange;

/**
 * Contrat à implémenter pour chaque listing facetté d'un projet.
 *
 * Chaque page de listing (références, solutions, articles...) possède sa propre
 * implémentation de cette interface. Étendre AbstractSearchConfig pour bénéficier
 * des valeurs par défaut sensées.
 */
interface SearchConfigInterface
{
    /**
     * Nom unique de l'action WP AJAX pour ce listing.
     * Doit être unique par projet et par listing.
     * Ex: 'tds_references_facets', 'tds_solutions_facets'
     */
    public function getAjaxAction(): string;

    /**
     * Nom de l'index Meilisearch à requêter.
     * Par défaut : valeur de config('meilisearch-facets.index').
     */
    public function getIndex(): string;

    /**
     * Slug du post type WordPress ciblé.
     * Ex: 'reference', 'solution', 'post'
     */
    public function getPostType(): string;

    /**
     * Liste des slugs de taxonomies WordPress utilisables comme facettes.
     * Ex: ['activity-sector', 'customer-type']
     */
    public function getFilterableTaxonomies(): array;

    /**
     * Groupes de plages numériques, indexés par nom de paramètre GET/POST.
     *
     * Chaque clé correspond au nom du paramètre dans le formulaire HTML.
     * Par convention, suffixer les noms par '_range' (ex: 'price_range').
     *
     * @return array<string, NumericRange[]>
     *
     * Exemple :
     * [
     *   'price_range' => [
     *     NumericRange::between('1000-2000', 'metas.price', 1000, 2000),
     *     NumericRange::between('2000-3000', 'metas.price', 2000, 3000),
     *     NumericRange::above('4000+', 'metas.price', 4000),
     *   ],
     * ]
     */
    public function getNumericRangeGroups(): array;

    /**
     * Nombre de résultats par page.
     */
    public function getHitsPerPage(): int;

    /**
     * Règles de tri Meilisearch appliquées par défaut (sans choix utilisateur).
     * Ex: ['metas.price:asc'], ['post_title:asc'], []
     */
    public function getDefaultSort(): array;

    /**
     * Attributs filtrables à configurer dans l'index Meilisearch.
     * Utilisé par la commande artisan meilisearch-facets:configure.
     * AbstractSearchConfig calcule automatiquement cette liste.
     */
    public function getFilterableAttributes(): array;

    /**
     * Attributs triables à configurer dans l'index Meilisearch.
     * Utilisé par la commande artisan meilisearch-facets:configure.
     */
    public function getSortableAttributes(): array;

    /**
     * Génère le HTML d'une carte à partir d'un hit Meilisearch.
     * Appelé pour chaque résultat lors d'une requête AJAX.
     *
     * @param  array  $hit  Document Meilisearch (contient 'ID', 'post_title', etc.)
     */
    public function renderHit(array $hit): string;

    /**
     * Génère le HTML de la pagination.
     *
     * @param  string  $link  URL de base avec les filtres courants en query string
     */
    public function renderPagination(int $totalPages, int $currentPage, string $link): string;

    /**
     * Retourne des filtres Meilisearch supplémentaires à partir de facettes non reconnues.
     *
     * Permet de gérer des champs meta ou des booléens indexés directement dans le document,
     * sans passer par les conventions taxonomie (_search_*) ou plage numérique (*_range).
     *
     * Exemple d'implémentation :
     *   if (!empty($unknownFacets['multisiteProject'])) {
     *       return ['multisiteProject = "1"'];
     *   }
     *   return [];
     *
     * @param  array<string, string>  $unknownFacets  Facettes POST non reconnues par le handler
     * @return string[]  Chaînes de filtre Meilisearch prêtes à être combinées avec AND
     */
    public function getCustomFilters(array $unknownFacets): array;
}
