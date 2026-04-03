<?php

/**
 * Plugin Name: Meilisearch Facets
 * Plugin URI:  https://github.com/amphibee/meilisearch-facets
 * Description: Système de filtres/facettes Meilisearch réutilisable pour les projets Pollora.
 * Version:     1.0.0
 * Author:      AmphiBee
 * License:     MIT
 */

declare(strict_types=1);

// Sécurité : interdit l'accès direct au fichier.
if (! defined('ABSPATH')) {
    exit;
}

define('MEILISEARCH_FACETS_DIR', __DIR__);
define('MEILISEARCH_FACETS_URL', plugin_dir_url(__FILE__));

/**
 * Ce fichier est chargé par WordPress avant que `init` ne soit déclenché.
 * Les hooks WordPress (add_action / add_filter) doivent être enregistrés ici
 * et non dans le ServiceProvider : dans Pollora, le ServiceProvider boot()
 * s'exécute APRÈS que WordPress a déclenché `init` via wp-settings.php.
 */

// Enregistrement des champs MetaBox (sidebar Gutenberg).
// MetaBox applique ce filtre pendant init — doit être enregistré avant.
add_filter('rwmb_meta_boxes', static function (array $meta_boxes): array {
    return (new \AmphiBee\MeilisearchFacets\Blocks\FacetedListingBlock())->registerBlockFields($meta_boxes);
});

add_filter('rwmb_meta_boxes', static function (array $meta_boxes): array {
    return (new \AmphiBee\MeilisearchFacets\Blocks\FacetFilterBlock())->registerBlockFields($meta_boxes);
});

// Contrainte parent : le bloc facet-filter ne peut être inséré que dans faceted-listing.
// MB Blocks ne gère pas la clé "parent" — on l'injecte via ce filtre WordPress.
add_filter('register_block_type_args', static function (array $args, string $name): array {
    if ($name === 'meta-box/facet-filter') {
        $args['parent'] = ['meta-box/faceted-listing'];
    }

    return $args;
}, 10, 2);

// Chargement du JS éditeur (InnerBlocks HOC) — enqueue_block_editor_assets
// est déclenché sur admin_enqueue_scripts, donc toujours après init.
add_action('enqueue_block_editor_assets', static function (): void {
    wp_enqueue_script(
        'meilisearch-facets-editor',
        MEILISEARCH_FACETS_URL . 'resources/js/blocks/editor.js',
        ['wp-hooks', 'wp-compose', 'wp-block-editor', 'wp-element'],
        '1.0.1',
        true
    );
});
