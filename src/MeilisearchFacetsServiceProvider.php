<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets;

use AmphiBee\MeilisearchFacets\Client\MeilisearchClient;
use AmphiBee\MeilisearchFacets\Console\ConfigureIndexCommand;
use AmphiBee\MeilisearchFacets\Service\FacetsSearchService;
use AmphiBee\MeilisearchFacets\Support\MeiliScout;
use Illuminate\Support\ServiceProvider;

class MeilisearchFacetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/meilisearch-facets.php',
            'meilisearch-facets'
        );

        // Résolu au premier usage, WordPress chargé : sans réglage propre au plugin,
        // ceux de MeiliScout (la clé de recherche de préférence).
        $this->app->singleton(MeilisearchClient::class, static function () {
            return new MeilisearchClient(
                url: rtrim(config('meilisearch-facets.url')
                    ?: MeiliScout::config('meili_host')
                    ?? 'http://localhost:7700', '/'),
                key: config('meilisearch-facets.key')
                    ?: MeiliScout::config('meili_search_key')
                    ?? MeiliScout::config('meili_key'),
            );
        });

        $this->app->singleton(FacetsSearchService::class, static function ($app) {
            return new FacetsSearchService($app->make(MeilisearchClient::class));
        });
    }

    public function boot(): void
    {
        // Publication de la config via : php artisan vendor:publish --tag=meilisearch-facets-config
        $this->publishes([
            __DIR__ . '/../config/meilisearch-facets.php' => config_path('meilisearch-facets.php'),
        ], 'meilisearch-facets-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureIndexCommand::class,
            ]);
        }
    }
}
