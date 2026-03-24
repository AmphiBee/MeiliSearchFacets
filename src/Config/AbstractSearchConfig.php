<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Config;

/**
 * Implémentation de base avec des valeurs par défaut sensées.
 * Étendre cette classe et surcharger uniquement ce dont vous avez besoin.
 */
abstract class AbstractSearchConfig implements SearchConfigInterface
{
    /**
     * Retourne le nom de l'index depuis la config Laravel.
     * Peut être surchargé si un listing utilise un index différent.
     */
    public function getIndex(): string
    {
        return config('meilisearch-facets.index', env('MEILI_INDEX_NAME', 'posts'));
    }

    /**
     * Aucune plage numérique par défaut.
     * Surcharger dans les listings qui filtrent par prix, durée, etc.
     */
    public function getNumericRangeGroups(): array
    {
        return [];
    }

    /**
     * 9 résultats par page (grille 3×3).
     */
    public function getHitsPerPage(): int
    {
        return 9;
    }

    /**
     * Aucun tri par défaut (Meilisearch applique son ranking natif).
     */
    public function getDefaultSort(): array
    {
        return [];
    }

    /**
     * Calcule automatiquement les attributs filtrables depuis les taxonomies
     * et les champs numériques déclarés dans getNumericRangeGroups().
     */
    public function getFilterableAttributes(): array
    {
        $attrs = ['terms.taxonomy', 'terms.slug', 'post_type', 'post_status'];

        foreach ($this->getNumericRangeGroups() as $ranges) {
            foreach ($ranges as $range) {
                $attrs[] = $range->field;
            }
        }

        return array_unique($attrs);
    }

    /**
     * Attributs triables par défaut : titre et date.
     * Les champs numériques des plages sont ajoutés automatiquement.
     */
    public function getSortableAttributes(): array
    {
        $attrs = ['post_title', 'post_date'];

        foreach ($this->getNumericRangeGroups() as $ranges) {
            foreach ($ranges as $range) {
                $attrs[] = $range->field;
            }
        }

        return array_unique($attrs);
    }

    /**
     * Aucun filtre custom par défaut.
     * Surcharger dans les configs qui filtrent par meta, booléens, etc.
     */
    public function getCustomFilters(array $unknownFacets): array
    {
        return [];
    }

    /**
     * Pagination HTML générique.
     * Surcharger pour utiliser un composant Blade du projet.
     */
    public function renderPagination(int $totalPages, int $currentPage, string $link): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<nav class="meilisearch-pagination flex gap-2 justify-center mt-8" aria-label="Pagination">';

        for ($i = 1; $i <= $totalPages; $i++) {
            $pageLink = add_query_arg('page', $i, $link);
            $isActive = $i === $currentPage;
            $ariaCurrent = $isActive ? ' aria-current="page"' : '';
            $class = $isActive ? 'page-link active' : 'page-link';

            $html .= sprintf(
                '<a href="%s" class="%s" data-page="%d"%s>%d</a>',
                esc_url($pageLink),
                esc_attr($class),
                $i,
                $ariaCurrent,
                $i
            );
        }

        $html .= '</nav>';

        return $html;
    }
}
