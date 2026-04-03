<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Blocks;

/**
 * Enregistre les champs MetaBox du bloc "Listing facetté" (meta-box/faceted-listing).
 *
 * Champs configurables dans la sidebar Gutenberg :
 *   - postType    : slug du CPT à afficher (doit être enregistré dans FacetedListingRegistry)
 *   - gridColumns : nombre de colonnes de la grille (1, 2, 3, 4)
 *   - hitsPerPage : résultats par page
 */
class FacetedListingBlock
{
    public function registerBlockFields(array $meta_boxes): array
    {
        $meta_boxes[] = [
            'title'           => 'Listing facetté (Meilisearch)',
            'id'              => 'faceted-listing',
            'icon'            => 'grid-view',
            'type'            => 'block',
            'category'        => 'widgets',

            // MB Blocks appelle ce callback depuis render_block() via son Resolver.
            // Le Resolver mappe les paramètres par nom et discard le retour — on doit echo.
            'render_callback' => function (array $attributes, string $content): void {
                echo $this->render($attributes, $content);
            },

            'fields' => [
                [
                    'type'        => 'text',
                    'id'          => 'postType',
                    'name'        => 'Type de contenu (CPT slug)',
                    'placeholder' => 'ex: reference, post, solution…',
                    'desc'        => 'Slug WordPress du CPT à afficher. Doit être enregistré dans FacetedListingRegistry via un FacetsHook.',
                ],
                [
                    'type'    => 'select',
                    'id'      => 'gridColumns',
                    'name'    => 'Colonnes de la grille',
                    'options' => [
                        '1' => '1 colonne',
                        '2' => '2 colonnes',
                        '3' => '3 colonnes (défaut)',
                        '4' => '4 colonnes',
                    ],
                    'default' => '3',
                ],
                [
                    'type'    => 'number',
                    'id'      => 'hitsPerPage',
                    'name'    => 'Résultats par page',
                    'min'     => 1,
                    'max'     => 100,
                    'default' => 12,
                    'desc'    => 'Nombre de cartes affichées par page.',
                ],
            ],
        ];

        return $meta_boxes;
    }

    public function render(array $attributes, string $content): string
    {
        // MB Blocks stocke les valeurs des champs dans $attributes['data'] par défaut.
        // On déplie cette structure pour que le template Blade accède à $attributes['postType'] etc.
        $data = $attributes['data'] ?? $attributes;

        $viewName = apply_filters('meilisearch_facets/faceted_listing_view', 'blocks.meilisearch.grid');

        if (! function_exists('view') || ! view()->exists($viewName)) {
            return '';
        }

        return view($viewName, ['attributes' => $data, 'content' => $content])->render();
    }
}
