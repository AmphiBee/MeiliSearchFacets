<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | URL du serveur Meilisearch
    |--------------------------------------------------------------------------
    | Vide : celle de MeiliScout, sinon http://localhost:7700.
    */
    'url' => env('MEILI_HOST'),

    /*
    |--------------------------------------------------------------------------
    | Clé d'API Meilisearch (master key ou search key)
    |--------------------------------------------------------------------------
    | Vide : celle de MeiliScout, sa clé de recherche si elle est réglée.
    */
    'key' => env('MEILI_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Nom de l'index par défaut
    |--------------------------------------------------------------------------
    | Ignoré avec MeiliScout 2.0 : l'index est celui que MeiliScout lit.
    */
    'index' => env('MEILI_INDEX_NAME', 'posts'),

    /*
    |--------------------------------------------------------------------------
    | Paramètres de recherche
    |--------------------------------------------------------------------------
    */
    'search' => [
        'matching_strategy' => env('MEILI_MATCHING_STRATEGY', 'last'),
    ],
];
