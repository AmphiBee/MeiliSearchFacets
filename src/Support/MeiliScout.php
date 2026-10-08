<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Support;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Ce que le plugin doit savoir de l'index des posts construit par MeiliScout.
 *
 * MeiliScout 2.0 nomme ses index (préfixe du site, index actif pendant une
 * migration) et regroupe les termes par taxonomie : `taxonomies.<taxonomie>.slug`.
 * Avant la 2.0, l'index s'appelait `posts` et les termes formaient une seule
 * liste `terms`, que Meilisearch aplatit : `terms.taxonomy` et `terms.slug`
 * y étaient comparés indépendamment, si bien qu'un tag homonyme d'une
 * catégorie satisfaisait un filtre sur la catégorie.
 */
final class MeiliScout
{
    /**
     * MeiliScout 2.0 ou plus est chargé : c'est lui qui nomme et paramètre l'index.
     */
    public static function managesIndexes(): bool
    {
        return class_exists(IndexNames::class);
    }

    /**
     * L'index des posts lu par les recherches, ou null sans MeiliScout 2.0.
     */
    public static function postsIndex(): ?string
    {
        return self::managesIndexes() ? IndexNames::active('posts') : null;
    }

    /**
     * Les documents de l'index actif portent les termes groupés par taxonomie (schéma 2 et plus).
     */
    public static function groupsTermsByTaxonomy(): bool
    {
        return self::managesIndexes() && IndexNames::activeSchema('posts') >= 2;
    }

    /**
     * Un réglage de MeiliScout (variable d'environnement, constante puis option), ou null.
     */
    public static function config(string $key): ?string
    {
        if (! class_exists(Config::class)) {
            return null;
        }

        $value = Config::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Filtre « le post a l'un de ces termes de la taxonomie ».
     *
     * @param  string[]  $slugs
     */
    public static function taxonomyFilter(string $taxonomy, array $slugs): string
    {
        $list = '[' . implode(', ', array_map(self::quote(...), array_values($slugs))) . ']';

        if (self::groupsTermsByTaxonomy()) {
            return "taxonomies.{$taxonomy}.slug IN {$list}";
        }

        return '(terms.taxonomy = ' . self::quote($taxonomy) . " AND terms.slug IN {$list})";
    }

    /**
     * Attribut dont la distribution donne les termes disponibles d'une taxonomie.
     */
    public static function taxonomyFacet(string $taxonomy): string
    {
        return self::groupsTermsByTaxonomy() ? "taxonomies.{$taxonomy}.slug" : 'terms.slug';
    }

    /**
     * Attribut de tri par titre : le titre sans accents ni majuscules depuis MeiliScout 2.0.
     */
    public static function titleSortAttribute(): string
    {
        return self::managesIndexes() && IndexNames::activeSchema('posts') >= 3 ? 'post_title_sort' : 'post_title';
    }

    /**
     * Une valeur de filtre Meilisearch entre apostrophes.
     */
    public static function quote(string|int|float $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
    }
}
