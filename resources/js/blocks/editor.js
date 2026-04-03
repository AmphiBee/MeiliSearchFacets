/**
 * meilisearch-facets — editor.js
 *
 * Injecte le composant InnerBlocks dans le bloc "meta-box/faceted-listing"
 * via un Higher-Order Component (HOC) sur le filtre editor.BlockEdit.
 *
 * Ce fichier est chargé uniquement dans l'éditeur Gutenberg (enqueue_block_editor_assets).
 * Il utilise les globales WordPress (wp.*) — aucune compilation requise.
 */

(function () {
    'use strict';

    // Vérification des dépendances WordPress globales
    if (typeof wp === 'undefined') {
        console.error('[MeilisearchFacets] wp global introuvable — script chargé trop tôt ?');
        return;
    }

    var hooks       = wp.hooks;
    var compose     = wp.compose;
    var blockEditor = wp.blockEditor;
    var element     = wp.element;

    if (! hooks || ! hooks.addFilter) {
        console.error('[MeilisearchFacets] wp.hooks.addFilter introuvable');
        return;
    }
    if (! compose || ! compose.createHigherOrderComponent) {
        console.error('[MeilisearchFacets] wp.compose.createHigherOrderComponent introuvable');
        return;
    }
    if (! blockEditor || ! blockEditor.InnerBlocks) {
        console.error('[MeilisearchFacets] wp.blockEditor.InnerBlocks introuvable');
        return;
    }
    if (! element || ! element.createElement) {
        console.error('[MeilisearchFacets] wp.element.createElement introuvable');
        return;
    }

    var createHigherOrderComponent = compose.createHigherOrderComponent;
    var InnerBlocks                = blockEditor.InnerBlocks;
    var createElement              = element.createElement;
    var Fragment                   = element.Fragment;

    /**
     * HOC : enveloppe le composant d'édition du bloc "meta-box/faceted-listing"
     * pour y ajouter une zone InnerBlocks permettant d'insérer des blocs
     * "Filtre de facette" (meta-box/facet-filter).
     */
    var withFacetedListingInnerBlocks = createHigherOrderComponent(
        function (BlockEdit) {
            return function (props) {
                if (props.name !== 'meta-box/faceted-listing') {
                    return createElement(BlockEdit, props);
                }

                return createElement(
                    Fragment,
                    null,
                    createElement(BlockEdit, props),
                    createElement(InnerBlocks, {
                        allowedBlocks: ['meta-box/facet-filter'],
                        templateLock: false,
                        renderAppender: InnerBlocks.ButtonBlockAppender,
                    })
                );
            };
        },
        'withFacetedListingInnerBlocks'
    );

    hooks.addFilter(
        'editor.BlockEdit',
        'meilisearch-facets/faceted-listing-inner-blocks',
        withFacetedListingInnerBlocks
    );
})();
