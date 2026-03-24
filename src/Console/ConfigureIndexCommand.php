<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Console;

use AmphiBee\MeilisearchFacets\Client\MeilisearchClient;
use AmphiBee\MeilisearchFacets\Config\SearchConfigInterface;
use Illuminate\Console\Command;

/**
 * Configure les settings Meilisearch d'un index pour un listing donné.
 *
 * Usage :
 *   php artisan meilisearch-facets:configure "App\Search\ReferenceSearchConfig"
 *
 * Cette commande :
 *   1. Récupère les attributs filtrables/triables déclarés dans la config
 *   2. Les fusionne avec les attributs existants de l'index (sans perte)
 *   3. Configure les ranking rules pour que le tri explicite ait la priorité
 */
class ConfigureIndexCommand extends Command
{
    protected $signature = 'meilisearch-facets:configure
        {config : Nom de classe complet (FQCN) d\'une implémentation de SearchConfigInterface}';

    protected $description = 'Configure les attributs filtrables, triables et les ranking rules Meilisearch pour un listing';

    public function __construct(private readonly MeilisearchClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $configClass = $this->argument('config');

        if (! class_exists($configClass)) {
            $this->error("Classe introuvable : {$configClass}");

            return Command::FAILURE;
        }

        $config = new $configClass();

        if (! $config instanceof SearchConfigInterface) {
            $this->error("{$configClass} doit implémenter SearchConfigInterface.");

            return Command::FAILURE;
        }

        $index = $config->getIndex();
        $this->info("Configuration de l'index : <comment>{$index}</comment>");
        $this->newLine();

        try {
            $this->configureFilterableAttributes($config, $index);
            $this->configureSortableAttributes($config, $index);
            $this->configureRankingRules($index);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Index configuré avec succès.');

        return Command::SUCCESS;
    }

    private function configureFilterableAttributes(SearchConfigInterface $config, string $index): void
    {
        $toAdd = $config->getFilterableAttributes();

        // Fusion avec les attributs existants pour ne pas écraser la config courante
        try {
            $existing = $this->client->getSettings($index, 'filterable-attributes');
        } catch (\RuntimeException) {
            $existing = [];
        }

        $merged = array_values(array_unique(array_merge($existing, $toAdd)));

        $this->line('Attributs filtrables : <info>' . implode(', ', $merged) . '</info>');
        $this->client->updateSettings($index, 'filterable-attributes', $merged);
        $this->line('  → <info>OK</info>');
    }

    private function configureSortableAttributes(SearchConfigInterface $config, string $index): void
    {
        $attrs = $config->getSortableAttributes();

        if (empty($attrs)) {
            return;
        }

        $this->line('Attributs triables : <info>' . implode(', ', $attrs) . '</info>');
        $this->client->updateSettings($index, 'sortable-attributes', $attrs);
        $this->line('  → <info>OK</info>');
    }

    private function configureRankingRules(string $index): void
    {
        // 'sort' en premier : le tri explicite de l'utilisateur prend la priorité
        // sur la pertinence textuelle.
        $rules = ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness'];

        $this->line('Ranking rules : <info>' . implode(', ', $rules) . '</info>');
        $this->client->updateSettings($index, 'ranking-rules', $rules);
        $this->line('  → <info>OK</info>');
    }
}
