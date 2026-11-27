<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Tests;

use AmphiBee\MeilisearchFacets\Client\MeilisearchClient;
use AmphiBee\MeilisearchFacets\MeilisearchFacetsServiceProvider;
use AmphiBee\MeilisearchFacets\Tests\Fixtures\ProduitsSearchConfig;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase;

require_once __DIR__ . '/Stubs/MeiliScoutConfig.php';

class ConfigureIndexCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MeilisearchFacetsServiceProvider::class];
    }

    /**
     * `php artisan package:discover` (à chaque `composer install`) charge toutes les commandes,
     * hors WordPress : aucune ne doit construire le client à ce moment-là.
     */
    public function test_charger_les_commandes_ne_construit_pas_le_client(): void
    {
        config(['meilisearch-facets.url' => null, 'meilisearch-facets.key' => null]);

        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('meilisearch-facets:configure', $commands);
        $this->assertFalse($this->app->resolved(MeilisearchClient::class));
    }

    public function test_la_commande_configure_toujours_l_index(): void
    {
        $client = $this->createMock(MeilisearchClient::class);
        $client->method('getSettings')->willReturn(['metas.price']);
        $envois = [];
        $client->expects($this->exactly(3))->method('updateSettings')
            ->willReturnCallback(function (string $index, string $setting, array $value) use (&$envois): bool {
                $envois[$setting] = [$index, $value];

                return true;
            });
        $this->app->instance(MeilisearchClient::class, $client);

        $this->artisan('meilisearch-facets:configure', ['config' => ProduitsSearchConfig::class])
            ->assertSuccessful();

        $this->assertSame(['produits', ['metas.price', 'post_type']], $envois['filterable-attributes']);
        $this->assertArrayHasKey('ranking-rules', $envois);
    }
}
