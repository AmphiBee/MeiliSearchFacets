<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Registry;

use AmphiBee\MeilisearchFacets\Config\SearchConfigInterface;

/**
 * Registre des SearchConfigs par type de contenu.
 *
 * Permet au bloc Gutenberg "Listing facetté" de retrouver l'action AJAX
 * associée à un CPT sans dupliquer la configuration.
 *
 * Enregistrement dans chaque FacetsHook (hook init) :
 *   FacetedListingRegistry::register(Reference::POST_SLUG, new ReferenceSearchConfig());
 */
class FacetedListingRegistry
{
    /** @var array<string, SearchConfigInterface> */
    private static array $configs = [];

    /**
     * Associe un type de contenu (CPT slug) à sa configuration de recherche.
     */
    public static function register(string $postType, SearchConfigInterface $config): void
    {
        static::$configs[$postType] = $config;
    }

    /**
     * Retourne la config associée à un CPT, ou null si non enregistré.
     */
    public static function getConfig(string $postType): ?SearchConfigInterface
    {
        return static::$configs[$postType] ?? null;
    }

    /**
     * Retourne l'action AJAX pour un CPT donné.
     * Retourne une chaîne vide si le CPT n'est pas enregistré.
     */
    public static function getAjaxAction(string $postType): string
    {
        return static::$configs[$postType]?->getAjaxAction() ?? '';
    }

    /**
     * Retourne tous les types de contenu enregistrés (utile pour le debug).
     *
     * @return string[]
     */
    public static function getRegisteredPostTypes(): array
    {
        return array_keys(static::$configs);
    }
}
