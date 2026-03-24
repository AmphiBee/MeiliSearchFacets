<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Hooks;

use AmphiBee\MeilisearchFacets\Config\SearchConfigInterface;

/**
 * Utilitaire pour intégrer les paramètres GET dans WP_Query lors du chargement initial de page.
 *
 * Le plugin ne peut pas enregistrer un hook Pollora dynamiquement (les attributs PHP sont
 * définis à la compilation). Cette classe fournit donc la logique via une méthode statique
 * que le projet appelle depuis son propre hook Pollora.
 *
 * Exemple d'utilisation dans le projet :
 *
 *   use Pollora\Attributes\Filter;
 *   use AmphiBee\MeilisearchFacets\Hooks\QueryIntegration;
 *
 *   class ReferenceQueryIntegration
 *   {
 *       #[Filter('tds_get_references_query_args', priority: 10)]
 *       public function handle(array $args): array
 *       {
 *           return QueryIntegration::apply($args, new ReferenceSearchConfig());
 *       }
 *   }
 *
 * Le filtre ('tds_get_references_query_args') doit ensuite être appliqué dans le template Blade :
 *
 *   @php
 *     $args = apply_filters('tds_get_references_query_args', ['post_type' => 'reference', ...]);
 *     $references = new WP_Query($args);
 *   @endphp
 */
class QueryIntegration
{
    /**
     * Injecte les paramètres GET courants dans les args WP_Query.
     *
     * Paramètres GET reconnus :
     *   - '_search_{taxonomy}' : filtre par terme de taxonomie (ex: _search_activity-sector=finance)
     *   - '{groupKey}_range'   : filtre par plage numérique (ex: price_range=1000-2000)
     *   - 'search_query'       : recherche textuelle libre
     *   - 'order'              : tri (asc, desc)
     */
    public static function apply(array $args, SearchConfigInterface $config): array
    {
        $params = array_map('sanitize_text_field', $_GET);

        if (empty($params)) {
            return $args;
        }

        $taxQuery    = [];
        $metaQuery   = ['relation' => 'AND'];
        $rangeGroups = $config->getNumericRangeGroups();

        foreach ($params as $key => $value) {
            if (empty($value)) {
                continue;
            }

            // Filtres taxonomiques : _search_{taxonomy}
            if (str_contains($key, '_search_')) {
                $taxonomy = str_replace('_search_', '', $key);
                if (taxonomy_exists($taxonomy)) {
                    $taxQuery[] = [
                        'taxonomy' => $taxonomy,
                        'field'    => 'slug',
                        'terms'    => is_array($value) ? $value : [$value],
                        'operator' => 'IN',
                    ];
                }
                continue;
            }

            // Filtres numériques : {groupKey}_range
            if (str_ends_with($key, '_range') && isset($rangeGroups[$key])) {
                $selectedKeys = is_array($value)
                    ? $value
                    : array_filter(array_map('trim', explode(',', $value)));

                $conditions = [];
                foreach ($rangeGroups[$key] as $range) {
                    if (in_array($range->key, $selectedKeys, strict: true)) {
                        $conditions[] = $range->toWpMetaCondition();
                    }
                }

                if (count($conditions) === 1) {
                    $metaQuery[$key] = $conditions[0];
                } elseif (count($conditions) > 1) {
                    $conditions['relation'] = 'OR';
                    $metaQuery[$key]        = $conditions;
                }
            }
        }

        if (! empty($taxQuery)) {
            $existing          = $args['tax_query'] ?? [];
            $args['tax_query'] = array_merge($existing, $taxQuery);
            $args['tax_query']['relation'] = 'AND';
        }

        if (count($metaQuery) > 1) {
            $args['meta_query'] = $metaQuery;
        }

        // Recherche textuelle
        if (! empty($params['search_query'])) {
            $args['s'] = $params['search_query'];
        }

        // Tri
        if (! empty($params['order'])) {
            [$orderby, $order] = match ($params['order']) {
                'asc'  => ['title', 'ASC'],
                'desc' => ['title', 'DESC'],
                default => ['date', 'DESC'],
            };
            $args['orderby'] = $orderby;
            $args['order']   = $order;
        }

        return $args;
    }
}
