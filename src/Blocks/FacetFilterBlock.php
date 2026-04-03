<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Blocks;

/**
 * Enregistre les champs MetaBox du bloc "Filtre de facette" (meta-box/facet-filter).
 *
 * Ce bloc est restreint au bloc parent "Listing facetté" (meta-box/faceted-listing)
 * via la propriété "parent" dans block.json.
 *
 * Champs configurables dans la sidebar Gutenberg :
 *   - displayType : type d'UI du filtre (select, radio, checkbox)
 *   - dataType    : type de données source (taxonomy, post_type, meta_boolean)
 *   - source      : slug de la taxonomie ou du CPT source
 *   - inputName   : nom HTML de l'input (convention : _search_* pour les taxonomies)
 *   - label       : intitulé visible dans l'UI
 *   - placeholder : texte de l'option vide / "Tous"
 */
class FacetFilterBlock
{
    public function registerBlockFields(array $meta_boxes): array
    {
        $meta_boxes[] = [
            'title'           => 'Filtre de facette',
            'id'              => 'facet-filter',
            'icon'            => 'filter',
            'type'            => 'block',
            'category'        => 'widgets',

            // MB Blocks appelle ce callback depuis render_block() via son Resolver.
            // Le Resolver mappe les paramètres par nom et discard le retour — on doit echo.
            'render_callback' => function (array $attributes, string $content): void {
                echo $this->render($attributes, $content);
            },

            'fields' => [
                [
                    'type'    => 'select',
                    'id'      => 'displayType',
                    'name'    => 'Type d\'affichage',
                    'options' => [
                        'select'   => 'Liste déroulante (select)',
                        'radio'    => 'Boutons radio (pilules)',
                        'checkbox' => 'Cases à cocher',
                    ],
                    'default' => 'select',
                    'desc'    => 'Comment les options sont affichées dans l\'interface.',
                ],
                [
                    'type'    => 'select',
                    'id'      => 'dataType',
                    'name'    => 'Type de données',
                    'options' => [
                        'taxonomy'     => 'Taxonomie WordPress (termes)',
                        'post_type'    => 'Type de contenu (posts)',
                        'meta_boolean' => 'Méta booléen (case à cocher unique)',
                    ],
                    'default' => 'taxonomy',
                    'desc'    => 'D\'où viennent les options du filtre.',
                ],
                [
                    'type'        => 'text',
                    'id'          => 'source',
                    'name'        => 'Source',
                    'placeholder' => 'ex: activity-sector, solution…',
                    'desc'        => 'Slug de la taxonomie ou du CPT. Laisser vide pour meta_boolean.',
                ],
                [
                    'type'        => 'text',
                    'id'          => 'inputName',
                    'name'        => 'Nom de l\'input',
                    'placeholder' => 'ex: _search_activity-sector',
                    'desc'        => 'Convention : _search_{taxonomy} pour les taxonomies. Nom du champ méta pour les autres types.',
                ],
                [
                    'type'        => 'text',
                    'id'          => 'label',
                    'name'        => 'Label',
                    'placeholder' => 'ex: Secteurs d\'activité',
                    'desc'        => 'Intitulé affiché dans le panneau de filtres.',
                ],
                [
                    'type'        => 'text',
                    'id'          => 'placeholder',
                    'name'        => 'Texte de l\'option vide',
                    'placeholder' => 'ex: Tous les secteurs',
                    'desc'        => 'Texte du bouton "Tous" (radio) ou de l\'option vide (select). Si vide, utilise le label.',
                ],
            ],
        ];

        return $meta_boxes;
    }

    public function render(array $attributes, string $content): string
    {
        // MB Blocks stocke les valeurs des champs dans $attributes['data'] par défaut.
        // On déplie cette structure pour que le template Blade accède à $attributes['displayType'] etc.
        $data = $attributes['data'] ?? $attributes;

        $viewName = apply_filters('meilisearch_facets/facet_filter_view', 'blocks.meilisearch.filter');

        if (! function_exists('view') || ! view()->exists($viewName)) {
            return '';
        }

        return view($viewName, ['attributes' => $data, 'content' => $content])->render();
    }
}
