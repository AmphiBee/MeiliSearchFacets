<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Console;

use AmphiBee\MeilisearchFacets\Client\MeilisearchClient;
use AmphiBee\MeilisearchFacets\Config\SearchConfigInterface;
use AmphiBee\MeilisearchFacets\Support\MeiliScout;
use Illuminate\Console\Command;
use Pollora\MeiliScout\Indexables\PostIndexable;

/**
 * Configure les settings Meilisearch d'un index pour un listing donné.
 *
 * Usage :
 *   php artisan meilisearch-facets:configure "App\Search\ReferenceSearchConfig"
 *
 * Sans MeiliScout 2.0, cette commande :
 *   1. Récupère les attributs filtrables/triables déclarés dans la config
 *   2. Les fusionne avec les attributs existants de l'index (sans perte)
 *   3. Configure les ranking rules pour que le tri explicite ait la priorité
 *
 * Avec MeiliScout 2.0, elle ne modifie rien : MeiliScout paramètre son index à
 * chaque indexation complète, sur un nouvel index, et ce qu'on y ajouterait à la
 * main serait perdu (remplacer les attributs triables casserait aussi ses tris).
 * Elle vérifie que les réglages de MeiliScout couvrent ceux du listing.
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

        if (MeiliScout::managesIndexes()) {
            return $this->checkMeiliScoutSettings($config, $index);
        }

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

    /**
     * Vérifie que les attributs du listing sont filtrables et triables dans les réglages de MeiliScout.
     */
    private function checkMeiliScoutSettings(SearchConfigInterface $config, string $index): int
    {
        $this->info("Index de MeiliScout : <comment>{$index}</comment>");
        $this->line('MeiliScout paramètre cet index lui-même : rien n\'est modifié, les réglages sont vérifiés.');
        $this->newLine();

        $settings = (new PostIndexable())->getIndexSettings();
        $missing  = [
            'filtrables' => $this->uncovered($config->getFilterableAttributes(), $settings['filterableAttributes'] ?? []),
            'triables'   => $this->uncovered($config->getSortableAttributes(), $settings['sortableAttributes'] ?? []),
        ];

        foreach ($missing as $kind => $attributes) {
            foreach ($attributes as $attribute) {
                $hint = str_starts_with($attribute, 'metas.')
                    ? 'ajouter la clé « ' . substr($attribute, 6) . ' » dans MeiliScout › Contenus › Clés méta, puis lancer une indexation complète'
                    : 'champ absent des réglages de MeiliScout';
                $this->warn("Attribut non {$kind} : {$attribute} ({$hint})");
            }
        }

        if ($missing['filtrables'] !== [] || $missing['triables'] !== []) {
            return Command::FAILURE;
        }

        $this->info('✓ Les réglages de MeiliScout couvrent ce listing.');

        return Command::SUCCESS;
    }

    /**
     * Les attributs qu'aucun réglage ne couvre : un attribut réglé couvre aussi ses sous-champs (`taxonomies`).
     *
     * @param  string[]  $wanted
     * @param  string[]  $configured
     * @return string[]
     */
    private function uncovered(array $wanted, array $configured): array
    {
        return array_values(array_filter($wanted, static function (string $attribute) use ($configured): bool {
            foreach ($configured as $setting) {
                if ($attribute === $setting || str_starts_with($attribute, $setting . '.')) {
                    return false;
                }
            }

            return true;
        }));
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
