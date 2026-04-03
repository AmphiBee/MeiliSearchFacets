# Meilisearch Facets

Plugin Composer réutilisable pour ajouter un système de **filtres/facettes Meilisearch** sur n'importe quel listing d'un projet Pollora (Laravel + WordPress).

---

## Table des matières

1. [Prérequis](#prérequis)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Créer un listing facetté en 6 étapes](#créer-un-listing-facetté-en-6-étapes)
   - [Étape 1 — Implémenter SearchConfigInterface](#étape-1--implémenter-searchconfiginterface)
   - [Étape 2 — Enregistrer les handlers](#étape-2--enregistrer-les-handlers)
   - [Étape 3 — Configurer l'index Meilisearch](#étape-3--configurer-lindex-meilisearch)
   - [Étape 4 — Créer les vues du thème](#étape-4--créer-les-vues-du-thème)
   - [Étape 5 — Configurer le bloc "Listing facetté"](#étape-5--configurer-le-bloc-listing-facetté)
   - [Étape 6 — Ajouter des filtres (blocs enfants)](#étape-6--ajouter-des-filtres-blocs-enfants)
5. [Référence — Les types de filtres Gutenberg](#référence--les-types-de-filtres-gutenberg)
6. [Référence — SearchConfigInterface](#référence--searchconfiginterface)
7. [Référence — Conventions de nommage des inputs](#référence--conventions-de-nommage-des-inputs)
8. [Cas d'usage avancés](#cas-dusage-avancés)
   - [Filtres custom (post_type, meta_boolean)](#filtres-custom-post_type-meta_boolean)
   - [Filtres numériques (prix, durée...)](#filtres-numériques-prix-durée)
   - [Listing sans filtres numériques](#listing-sans-filtres-numériques)
   - [Pagination personnalisée](#pagination-personnalisée)
   - [Plusieurs listings sur un même projet](#plusieurs-listings-sur-un-même-projet)
   - [Implémentation manuelle (sans Gutenberg)](#implémentation-manuelle-sans-gutenberg)
9. [Architecture interne](#architecture-interne)
10. [Résolution de problèmes](#résolution-de-problèmes)

---

## Prérequis

- Projet Pollora (Laravel 12 + WordPress 6+)
- Meilisearch accessible (local ou distant)
- Plugin MeiliScout installé et configuré pour indexer les posts WordPress
  - Les documents indexés doivent contenir un champ `terms` de la forme `[{taxonomy, slug, name}]`
  - Les metas numériques filtrables doivent être dans un objet `metas` (ex: `metas.price`)
- AlpineJS chargé dans le thème

---

## Installation

### Via Composer (Satis privé — méthode recommandée)

```bash
composer require amphibee/meilisearch-facets
```

Le ServiceProvider est auto-découvert par Laravel.

### Installation temporaire (développement)

Copier le plugin dans `public/content/plugins/meilisearch-facets/` et enregistrer le ServiceProvider dans `bootstrap/providers.php` :

```php
// bootstrap/providers.php
return [
    AmphiBee\MeilisearchFacets\MeilisearchFacetsServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    // ...
];
```

Puis conditionner chaque handler AJAX à l'activation du plugin dans l'admin WordPress, directement dans le callback `#[Action('init')]` du Hook Pollora :

```php
#[Action('init')]
public function registerAjaxHandler(): void
{
    if (! in_array('meilisearch-facets/meilisearch-facets.php', (array) get_option('active_plugins', []), true)) {
        return;
    }

    FacetsAjaxHandler::register(new MySearchConfig());
}
```

> **Pourquoi cette séparation ?** Dans le cycle de démarrage de Pollora, `bootstrap/providers.php` est évalué avant que WordPress soit chargé. Le ServiceProvider doit donc être enregistré inconditionnellement pour que les bindings Laravel (`MeilisearchClient`, `FacetsSearchService`) soient disponibles dans le conteneur. En revanche, `get_option()` n'est disponible qu'une fois WordPress chargé — ce qui est garanti au moment où `#[Action('init')]` s'exécute. C'est donc le bon endroit pour vérifier `active_plugins` et décider d'enregistrer ou non les actions AJAX.

### Publier la config

```bash
php artisan vendor:publish --tag=meilisearch-facets-config
```

Cela crée `config/meilisearch-facets.php` dans le projet.

---

## Configuration

### Variables d'environnement (`.env`)

```dotenv
MEILI_HOST=http://localhost:7700
MEILI_KEY=votre_master_key_ou_search_key
MEILI_INDEX_NAME=posts
MEILI_MATCHING_STRATEGY=last
```

### `config/meilisearch-facets.php`

```php
return [
    'url'    => env('MEILI_HOST', 'http://localhost:7700'),
    'key'    => env('MEILI_KEY'),
    'index'  => env('MEILI_INDEX_NAME', 'posts'),
    'search' => [
        'matching_strategy' => env('MEILI_MATCHING_STRATEGY', 'last'),
    ],
];
```

### Indexer les posts

Après installation de MeiliScout, indexer les posts WordPress :

```bash
ddev exec wp meiliscout index --clear
```

---

## Créer un listing facetté en 6 étapes

L'approche recommandée utilise les blocs Gutenberg : les filtres et la mise en page se configurent directement dans l'éditeur, sans toucher aux templates Blade. La partie PHP reste nécessaire pour la logique de recherche.

L'exemple ci-dessous utilise un CPT `reference` avec une taxonomie `activity-sector` et un filtre relationnel.

---

### Étape 1 — Implémenter SearchConfigInterface

Créer une classe dans `app/Search/` qui étend `AbstractSearchConfig`.

```php
<?php
// app/Search/ReferenceSearchConfig.php

declare(strict_types=1);

namespace App\Search;

use AmphiBee\MeilisearchFacets\Config\AbstractSearchConfig;

class ReferenceSearchConfig extends AbstractSearchConfig
{
    /**
     * Nom unique de l'action AJAX pour ce listing.
     * Doit être unique dans tout le projet.
     */
    public function getAjaxAction(): string
    {
        return 'myproject_references_facets';
    }

    /**
     * Slug du CPT WordPress ciblé.
     */
    public function getPostType(): string
    {
        return 'reference';
    }

    /**
     * Taxonomies utilisables comme facettes.
     * Utiliser les slugs WordPress exacts.
     */
    public function getFilterableTaxonomies(): array
    {
        return ['activity-sector'];
    }

    /**
     * Rendu d'une carte résultat.
     * Appelé pour chaque hit retourné par Meilisearch.
     * $hit['ID'] contient l'ID WordPress du post.
     */
    public function renderHit(array $hit): string
    {
        return view('components.meilisearch.card', ['post' => get_post($hit['ID'])])->render();
    }

    /**
     * Filtres custom pour les meta non reconnues automatiquement
     * (filtres post_type ou meta_boolean configurés dans Gutenberg).
     */
    public function getCustomFilters(array $unknownFacets): array
    {
        $filters = [];

        if (! empty($unknownFacets['related_solutions'])) {
            $id = (int) $unknownFacets['related_solutions'];
            $filters[] = "metas.related_solutions = {$id}";
        }

        return $filters;
    }
}
```

**Points importants :**
- `getAjaxAction()` doit être **unique** par listing et par projet.
- `getPostType()` doit correspondre exactement au slug du CPT WordPress.
- `getCustomFilters()` est requis uniquement si des filtres `post_type` ou `meta_boolean` sont utilisés dans Gutenberg (voir [Filtres custom](#filtres-custom-post_type-meta_boolean)).

---

### Étape 2 — Enregistrer les handlers

Créer un Hook Pollora dans `app/Cms/Hooks/Search/` :

```php
<?php
// app/Cms/Hooks/Search/ReferenceFacetsHook.php

declare(strict_types=1);

namespace App\Cms\Hooks\Search;

use AmphiBee\MeilisearchFacets\Ajax\FacetsAjaxHandler;
use AmphiBee\MeilisearchFacets\Registry\FacetedListingRegistry;
use App\Search\ReferenceSearchConfig;
use Pollora\Attributes\Action;

class ReferenceFacetsHook
{
    #[Action('init')]
    public function registerAjaxHandler(): void
    {
        if (! in_array('meilisearch-facets/meilisearch-facets.php', (array) get_option('active_plugins', []), true)) {
            return;
        }

        $config = new ReferenceSearchConfig();

        // Enregistre l'action WordPress wp_ajax_* pour les requêtes AJAX
        FacetsAjaxHandler::register($config);

        // Enregistre la config dans le registre pour que le bloc Gutenberg
        // puisse retrouver la bonne action AJAX à partir du slug CPT
        FacetedListingRegistry::register('reference', $config);
    }
}
```

> La clé passée à `FacetedListingRegistry::register()` doit correspondre exactement au slug CPT qui sera saisi dans la sidebar du bloc Gutenberg.

> Pollora découvre automatiquement les classes dans `app/Cms/Hooks/` grâce à son système de découverte basé sur les attributs PHP. Aucune déclaration supplémentaire n'est nécessaire.

---

### Étape 3 — Configurer l'index Meilisearch

Lancer la commande Artisan fournie par le plugin pour configurer automatiquement l'index :

```bash
php artisan meilisearch-facets:configure "App\Search\ReferenceSearchConfig"
```

Cette commande configure dans l'index Meilisearch :
- **Attributs filtrables** : `terms.taxonomy`, `terms.slug`, `post_type`, `post_status`, + les champs numériques déclarés
- **Attributs triables** : `post_title`, `post_date`, + les champs numériques
- **Ranking rules** : `sort` en premier (pour que le tri explicite prime sur la pertinence)

> Relancer cette commande à chaque ajout de nouveau champ numérique.

---

### Étape 4 — Créer les vues du thème

Le plugin appelle deux vues Blade qui doivent exister dans le thème. Sans elles, les blocs Gutenberg ne rendent rien.

#### `resources/views/blocks/meilisearch/grid.blade.php`

Vue du bloc conteneur. Reçoit `$attributes` (valeurs de la sidebar Gutenberg) et `$content` (HTML des filtres déjà rendu par les blocs enfants).

```blade
@php
    use AmphiBee\MeilisearchFacets\Registry\FacetedListingRegistry;

    $postType    = $attributes['postType'] ?? '';
    $gridColumns = $attributes['gridColumns'] ?? '3';
    $hitsPerPage = $attributes['hitsPerPage'] ?? 12;
    $ajaxAction  = FacetedListingRegistry::getAjaxAction($postType);
@endphp

<section
    x-data="MeilisearchFacets()"
    data-ajax-action="{{ $ajaxAction }}"
    data-hits-per-page="{{ $hitsPerPage }}"
    x-cloak
>
    <div class="filters">
        {!! $content !!}
    </div>

    <div class="grid grid-cols-{{ $gridColumns }}" x-ref="grid"></div>

    <div x-ref="pagination"></div>
</section>
```

#### `resources/views/blocks/meilisearch/filter.blade.php`

Vue de chaque bloc filtre enfant. Reçoit `$attributes` (configuration du filtre dans la sidebar).

```blade
@php
    $displayType = $attributes['displayType'] ?? 'select';
    $dataType    = $attributes['dataType'] ?? 'taxonomy';
    $source      = $attributes['source'] ?? '';
    $inputName   = $attributes['inputName'] ?? '';
    $label       = $attributes['label'] ?? '';
    $placeholder = $attributes['placeholder'] ?: $label;

    $options = match ($dataType) {
        'taxonomy'  => get_terms(['taxonomy' => $source, 'hide_empty' => true, 'orderby' => 'name']),
        'post_type' => get_posts(['post_type' => $source, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']),
        default     => [],
    };
@endphp

@if ($dataType === 'meta_boolean')
    <label>
        <input type="checkbox" name="{{ $inputName }}" value="1">
        {{ $label }}
    </label>

@elseif ($displayType === 'select')
    <div class="filter-group">
        <label>{{ $label }}</label>
        <select name="{{ $inputName }}">
            <option value="">{{ $placeholder }}</option>
            @foreach ($options as $option)
                <option value="{{ $dataType === 'taxonomy' ? $option->slug : $option->ID }}">
                    {{ $dataType === 'taxonomy' ? $option->name : $option->post_title }}
                </option>
            @endforeach
        </select>
    </div>

@elseif ($displayType === 'radio')
    <div class="filter-group">
        <label>{{ $label }}</label>
        <div class="filter-pills">
            <label><input type="radio" name="{{ $inputName }}" value=""> {{ $placeholder }}</label>
            @foreach ($options as $option)
                <label>
                    <input type="radio" name="{{ $inputName }}" value="{{ $dataType === 'taxonomy' ? $option->slug : $option->ID }}">
                    {{ $dataType === 'taxonomy' ? $option->name : $option->post_title }}
                </label>
            @endforeach
        </div>
    </div>

@elseif ($displayType === 'checkbox')
    <div class="filter-group">
        <label>{{ $label }}</label>
        <div class="filter-checkboxes">
            @foreach ($options as $option)
                <label>
                    <input type="checkbox" name="{{ $inputName }}[]" value="{{ $dataType === 'taxonomy' ? $option->slug : $option->ID }}">
                    {{ $dataType === 'taxonomy' ? $option->name : $option->post_title }}
                </label>
            @endforeach
        </div>
    </div>
@endif
```

#### `resources/views/components/meilisearch/card.blade.php`

Composant carte appelé depuis `renderHit()` dans chaque `SearchConfig`. À adapter selon le design du projet.

```blade
@props(['post'])

<article class="card">
    <a href="{{ get_permalink($post->ID) }}">
        @if ($thumbnail = get_the_post_thumbnail_url($post->ID, 'medium'))
            <img src="{{ $thumbnail }}" alt="{{ $post->post_title }}">
        @endif
        <h3>{{ $post->post_title }}</h3>
    </a>
</article>
```

Si plusieurs CPT ont des mises en page différentes, chaque `SearchConfig` peut pointer vers son propre composant (`card-reference.blade.php`, `card-product.blade.php`, etc.).

#### Enregistrement du composant Alpine.js

À faire une seule fois dans le JS principal du thème :

```javascript
// resources/assets/js/frontend/app.js
import Alpine from 'alpinejs';
import MeilisearchFacets from '../../../vendor/amphibee/meilisearch-facets/resources/js/facets.js';

Alpine.data('MeilisearchFacets', MeilisearchFacets);
Alpine.start();
```

---

### Étape 5 — Configurer le bloc "Listing facetté"

1. Dans Gutenberg, insérer le bloc **"Listing facetté (Meilisearch)"**
2. Dans la sidebar à droite, renseigner :

| Champ | Description | Exemple |
|---|---|---|
| **Type de contenu (CPT slug)** | Slug enregistré dans `FacetedListingRegistry` | `reference` |
| **Colonnes de la grille** | Nombre de colonnes sur desktop | `3` |
| **Résultats par page** | Nombre de cartes affichées par page | `12` |

La grille et la pagination sont rendues automatiquement. Les blocs enfants définissent les filtres.

---

### Étape 6 — Ajouter des filtres (blocs enfants)

À l'intérieur du bloc "Listing facetté", cliquer sur **"+"** pour ajouter des blocs. Seul le bloc **"Filtre de facette"** est proposé.

```
┌─ Bloc "Listing facetté" ─────────────────────────────────────────┐
│  postType=reference, gridColumns=3, hitsPerPage=12               │
│                                                                   │
│  ┌─ Bloc "Filtre de facette" ──────────────────────────────┐     │
│  │  displayType=radio, dataType=taxonomy                   │     │
│  │  source=activity-sector                                 │  →  <input type="radio" name="_search_activity-sector">
│  │  inputName=_search_activity-sector                      │     │
│  └─────────────────────────────────────────────────────────┘     │
│                                                                   │
│  ┌─ Bloc "Filtre de facette" ──────────────────────────────┐     │
│  │  displayType=select, dataType=post_type                 │     │
│  │  source=solution                                        │  →  <select name="related_solutions">
│  │  inputName=related_solutions                            │     │
│  └─────────────────────────────────────────────────────────┘     │
│                                                                   │
│  [Grille de résultats — remplie via AJAX]                        │
│  [Pagination]                                                    │
└──────────────────────────────────────────────────────────────────┘
```

Chaque filtre se configure dans la sidebar :

| Champ | Description |
|---|---|
| **Type d'affichage** | Comment les options sont rendues (`select`, `radio`, `checkbox`) |
| **Type de données** | D'où viennent les options (`taxonomy`, `post_type`, `meta_boolean`) |
| **Source** | Slug de la taxonomie ou du CPT |
| **Nom de l'input** | Nom HTML — doit respecter les conventions (voir ci-dessous) |
| **Label** | Intitulé visible dans l'interface |
| **Texte de l'option vide** | Texte du "Tous" / option par défaut |

---

## Référence — Les types de filtres Gutenberg

### Types d'affichage

| Type | Rendu HTML | Sélection | Idéal pour |
|---|---|---|---|
| `select` | `<select>` | Unique | Taxonomies avec beaucoup de termes |
| `radio` | Boutons pilules horizontaux | Unique | Taxonomies avec peu de termes (~5), inclut "Tous" automatiquement |
| `checkbox` | Cases à cocher verticales | Multiple (tableau) | Sélection de plusieurs valeurs |

### Types de données

#### `taxonomy` — Taxonomie WordPress

Charge les termes via `get_terms()`. Le nom de l'input **doit commencer par `_search_`** suivi du slug de la taxonomie.

```
dataType  : taxonomy
source    : activity-sector
inputName : _search_activity-sector
```

#### `post_type` — Type de contenu

Charge les posts d'un CPT via `get_posts()`. La valeur envoyée est l'ID du post.
Le nom de l'input correspond au champ méta stocké dans Meilisearch.
Nécessite une implémentation dans `getCustomFilters()`.

```
dataType  : post_type
source    : solution
inputName : related_solutions
```

#### `meta_boolean` — Méta booléen

Affiche une case à cocher unique. Quand cochée, envoie la valeur `1`.
Nécessite une implémentation dans `getCustomFilters()`.

```
dataType  : meta_boolean
source    : (laisser vide)
inputName : multisiteProject
label     : Projets multi-sites uniquement
```

---

## Référence — SearchConfigInterface

| Méthode | Retour | Obligatoire | Description |
|---------|--------|-------------|-------------|
| `getAjaxAction()` | `string` | ✅ | Nom unique de l'action WP AJAX |
| `getPostType()` | `string` | ✅ | Slug du CPT WordPress |
| `getFilterableTaxonomies()` | `array` | ✅ | Slugs des taxonomies filtrables |
| `renderHit(array $hit)` | `string` | ✅ | HTML d'une carte (hit Meilisearch) |
| `getNumericRangeGroups()` | `array` | — | Groupes de plages numériques |
| `getCustomFilters(array $unknownFacets)` | `array` | — | Filtres Meilisearch pour les meta non reconnus |
| `getHitsPerPage()` | `int` | — | Résultats par page (défaut: 9) |
| `getDefaultSort()` | `array` | — | Tri par défaut Meilisearch |
| `getFilterableAttributes()` | `array` | — | Attributs filtrables (calculé auto) |
| `getSortableAttributes()` | `array` | — | Attributs triables (calculé auto) |
| `renderPagination(...)` | `string` | — | HTML de pagination (défaut: simple) |

Les méthodes sans ✅ ont une implémentation par défaut dans `AbstractSearchConfig`.

---

## Référence — Conventions de nommage des inputs

Le composant JS interprète les inputs du formulaire selon ces conventions :

| Pattern du `name` | Rôle | Exemple |
|---|---|---|
| `_search_{taxonomy}` | Filtre par taxonomie (automatique) | `_search_activity-sector` |
| `{groupe}_range` | Filtre par plage numérique | `price_range`, `duration_range` |
| `search_query` | Recherche textuelle libre | — |
| `order` | Tri des résultats | — |
| Tout autre `name` | Meta custom → transmis à `getCustomFilters()` | `related_solutions`, `multisiteProject` |

> Les clés des groupes dans `getNumericRangeGroups()` doivent correspondre exactement aux `name` des inputs HTML.

**Comportement de la recherche textuelle :** l'input `name="search_query"` doit être de type `search` ou `text`. Le composant écoute `keydown` + `Enter` (cross-browser) et `input` lorsque le champ est vidé via le bouton ✕.

---

## Cas d'usage avancés

### Filtres custom (post_type, meta_boolean)

Les filtres de type `post_type` et `meta_boolean` configurés dans Gutenberg arrivent dans `getCustomFilters()` de la SearchConfig. Ils doivent y être transformés en expressions de filtre Meilisearch valides.

#### 1. Déclarer les filtres dans la config

```php
public function getCustomFilters(array $unknownFacets): array
{
    $filters = [];

    // Relationnel : valeur = ID du post lié
    if (! empty($unknownFacets['related_solutions'])) {
        $id = (int) $unknownFacets['related_solutions'];
        $filters[] = "metas.related_solutions = {$id}";
    }

    // Booléen : coché = valeur "1", non coché = absent du tableau
    if (! empty($unknownFacets['multisiteProject'])) {
        $filters[] = 'metas.multisiteProject = 1';
    }

    return $filters;
}
```

#### 2. S'assurer que les metas sont bien indexées

Meiliscout indexe les metas via `get_post_meta($id, $key, true)`, ce qui retourne uniquement la première valeur pour les champs multi-valeur. Pour les champs stockant plusieurs IDs (Meta Box "Multiple Post"), utiliser le filtre `meiliscout/post/document` :

```php
#[Filter('meiliscout/post/document', priority: 10)]
public function normalizeDocument(array $document, WP_Post $post): array
{
    if ($post->post_type !== 'your_post_type') {
        return $document;
    }

    // Booléen : forcer la présence du champ même si jamais coché
    $value = get_post_meta($post->ID, 'multisiteProject', true);
    $document['metas']['multisiteProject'] = ($value !== '') ? (int) $value : 0;

    // Multi-valeur : get_post_meta(..., false) retourne toutes les valeurs
    $document['metas']['related_solutions'] = array_values(
        array_map('intval', array_filter((array) get_post_meta($post->ID, 'related_solutions', false)))
    );

    return $document;
}
```

> **Pourquoi ne pas lire `$document['metas']` ?** Lors de l'indexation en temps réel (`PostSingleIndexer`), Meiliscout crée une nouvelle instance de `PostIndexable` dont `$metaKeys` est vide — `getMetaData()` retourne donc `[]`. En appelant `get_post_meta()` directement, le hook fonctionne dans les deux contextes (indexation bulk et temps réel).

#### 3. Configurer les attributs filtrables dans Meiliscout

Dans l'admin Meiliscout, ajouter les clés **sans préfixe** (Meiliscout ajoute `metas.` automatiquement) :
- `multisiteProject`
- `related_solutions`

Puis relancer l'indexation :

```bash
wp meiliscout index --clear
```

---

### Filtres numériques (prix, durée...)

Les filtres numériques ne sont pas encore configurables depuis Gutenberg — ils se déclarent dans la `SearchConfig` et les inputs HTML correspondants sont gérés dans le template Blade du bloc.

#### 1. Pousser des metas numériques dans l'index

Si Meiliscout n'indexe pas automatiquement vos metas numériques dans un objet `metas`, créer une commande Artisan pour le faire :

```php
<?php
// app/Console/Commands/SyncProductPricesToMeilisearch.php

namespace App\Console\Commands;

use AmphiBee\MeilisearchFacets\Client\MeilisearchClient;
use Illuminate\Console\Command;

class SyncProductPricesToMeilisearch extends Command
{
    protected $signature   = 'search:sync-products';
    protected $description = 'Synchronise les metas numériques des produits vers Meilisearch';

    public function handle(MeilisearchClient $client): int
    {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as price
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'price'
            WHERE p.post_type = 'product' AND p.post_status = 'publish'
        ");

        $documents = array_map(fn($row) => [
            'ID'    => (int) $row->ID,
            'metas' => ['price' => (int) $row->price],
        ], $results);

        foreach (array_chunk($documents, 100) as $batch) {
            $client->updateDocuments(config('meilisearch-facets.index'), $batch);
        }

        $this->info(sprintf('%d documents mis à jour.', count($documents)));

        return Command::SUCCESS;
    }
}
```

```bash
php artisan search:sync-products
```

#### 2. Déclarer les plages dans la config

```php
public function getNumericRangeGroups(): array
{
    return [
        'price_range' => [
            NumericRange::between('0-50',    'metas.price', 0,   50),
            NumericRange::between('50-100',  'metas.price', 50,  100),
            NumericRange::above('200+',      'metas.price', 200),
        ],
        'duration_range' => [
            NumericRange::between('1-7',  'metas.days', 1,  7),
            NumericRange::between('8-14', 'metas.days', 8,  14),
            NumericRange::above('15+',    'metas.days', 15),
        ],
    ];
}
```

#### 3. Inputs HTML dans le template Blade du bloc

```blade
{{-- price_range doit correspondre exactement à la clé du groupe --}}
<label><input type="checkbox" name="price_range" value="0-50">   0 – 50 €</label>
<label><input type="checkbox" name="price_range" value="50-100"> 50 – 100 €</label>
<label><input type="checkbox" name="price_range" value="200+">   200 € et plus</label>
```

---

### Listing sans filtres numériques

Si votre listing filtre uniquement par taxonomies, `getNumericRangeGroups()` retourne `[]` par défaut dans `AbstractSearchConfig` — rien à faire.

```php
class ArticleSearchConfig extends AbstractSearchConfig
{
    public function getAjaxAction(): string   { return 'myproject_articles_facets'; }
    public function getPostType(): string     { return 'post'; }

    public function getFilterableTaxonomies(): array
    {
        return ['category', 'post_tag'];
    }

    public function getHitsPerPage(): int { return 10; }

    public function renderHit(array $hit): string
    {
        return view('components.meilisearch.card', ['post' => get_post($hit['ID'])])->render();
    }
}
```

---

### Pagination personnalisée

Surcharger `renderPagination()` pour utiliser un composant Blade existant :

```php
public function renderPagination(int $totalPages, int $currentPage, string $link): string
{
    return view('components.utilities.pagination', [
        'total_pages'  => $totalPages,
        'current_page' => $currentPage,
        'link'         => $link,
    ])->render();
}
```

---

### Plusieurs listings sur un même projet

Chaque listing est **indépendant** : créer une config + un hook par listing.

```
app/Search/
    ReferenceSearchConfig.php   → action: 'myproject_references_facets'
    SolutionSearchConfig.php    → action: 'myproject_solutions_facets'
    ArticleSearchConfig.php     → action: 'myproject_articles_facets'

app/Cms/Hooks/Search/
    ReferenceFacetsHook.php
    SolutionFacetsHook.php
    ArticleFacetsHook.php
```

---

### Implémentation manuelle (sans Gutenberg)

Si vous n'utilisez pas les blocs Gutenberg, vous pouvez construire le template Blade manuellement et initialiser le composant Alpine.js directement.

#### Template Blade

```blade
{{-- themes/{theme}/resources/views/blocks/listings/products.blade.php --}}

<section
    x-data="MeilisearchFacets()"
    data-ajax-action="myproject_products_facets"
    x-cloak
>
    {{-- Filtre par taxonomie --}}
    <select name="_search_product-category">
        <option value="">Toutes les catégories</option>
        @foreach(get_terms(['taxonomy' => 'product-category', 'hide_empty' => true]) as $term)
            <option value="{{ $term->slug }}">{{ $term->name }}</option>
        @endforeach
    </select>

    {{-- Filtre numérique par plage de prix --}}
    <fieldset>
        <legend>Prix</legend>
        <label><input type="checkbox" name="price_range" value="0-50">   Moins de 50 €</label>
        <label><input type="checkbox" name="price_range" value="50-100"> 50 – 100 €</label>
        <label><input type="checkbox" name="price_range" value="100-200">100 – 200 €</label>
        <label><input type="checkbox" name="price_range" value="200+">   200 € et plus</label>
    </fieldset>

    {{-- Recherche textuelle --}}
    <input type="search" name="search_query" placeholder="Rechercher...">

    {{-- x-ref="grid" et x-ref="pagination" sont obligatoires --}}
    <div class="grid" x-ref="grid"></div>
    <div x-ref="pagination"></div>
</section>
```

**Points importants :**
- `x-data="MeilisearchFacets()"` déclenche automatiquement une première requête AJAX au `init` pour remplir la grille.
- `data-ajax-action` doit correspondre exactement à `getAjaxAction()`.
- `x-ref="grid"` et `x-ref="pagination"` sont requis — le composant JS y injecte le HTML retourné par le serveur.
- `x-cloak` masque la section jusqu'à ce qu'Alpine soit initialisé.

#### Enregistrement du composant Alpine.js

```javascript
// themes/{theme}/resources/assets/js/frontend/app.js

import Alpine from 'alpinejs';
import MeilisearchFacets from '../../../../public/content/plugins/meilisearch-facets/resources/js/facets.js';

Alpine.data('MeilisearchFacets', MeilisearchFacets);

Alpine.start();
```

> Une fois le plugin installé via Composer dans `vendor/`, adapter le chemin :
> ```javascript
> import MeilisearchFacets from '../../../vendor/amphibee/meilisearch-facets/resources/js/facets.js';
> ```

#### URLs partageables (filtres pré-appliqués au chargement initial)

Pour que les filtres actifs dans l'URL (ex: `?_search_product-category=tshirt`) soient appliqués dès le chargement serveur, ajouter ce hook :

```php
use AmphiBee\MeilisearchFacets\Hooks\QueryIntegration;
use Pollora\Attributes\Filter;

#[Filter('myproject_get_products_query_args', priority: 10)]
public function buildQueryArgs(array $args): array
{
    return QueryIntegration::apply($args, new ProductSearchConfig());
}
```

Ce filtre est appliqué dans le template Blade via `apply_filters('myproject_get_products_query_args', [...])` avant l'instanciation de `WP_Query`.

---

## Architecture interne

```
meilisearch-facets/
├── src/
│   ├── MeilisearchFacetsServiceProvider.php   # Enregistrement Laravel (singleton, config, commande)
│   ├── Client/
│   │   └── MeilisearchClient.php              # Wrapper cURL bas niveau (search, multi-search, settings)
│   ├── Config/
│   │   ├── SearchConfigInterface.php          # Contrat à implémenter par projet
│   │   └── AbstractSearchConfig.php           # Valeurs par défaut (index, tri, pagination, attributs)
│   ├── DTO/
│   │   ├── NumericRange.php                   # Plage numérique (min/max, filtres Meili + WP_Query)
│   │   ├── SearchRequest.php                  # Paramètres d'une recherche (query, filtres, page, tri)
│   │   └── SearchResult.php                   # Résultat normalisé (hits, total, pages, facettes)
│   ├── Service/
│   │   └── FacetsSearchService.php            # Orchestration (search, getAvailableRanges, mapSlugs)
│   ├── Ajax/
│   │   └── FacetsAjaxHandler.php              # Handler WP AJAX générique
│   ├── Blocks/
│   │   ├── FacetedListingBlock.php            # Bloc Gutenberg conteneur
│   │   └── FacetFilterBlock.php               # Bloc Gutenberg filtre enfant
│   ├── Registry/
│   │   └── FacetedListingRegistry.php         # Registre CPT slug → SearchConfig
│   ├── Console/
│   │   └── ConfigureIndexCommand.php          # php artisan meilisearch-facets:configure
│   └── Hooks/
│       └── QueryIntegration.php               # Injection $_GET → WP_Query (chargement initial)
├── config/
│   └── meilisearch-facets.php                 # Config publiable (url, key, index, strategy)
└── resources/
    ├── js/
    │   ├── facets.js                          # Composant Alpine.js générique
    │   └── blocks/editor.js                  # HOC InnerBlocks pour l'éditeur Gutenberg
    └── blocks/
        ├── faceted-listing/block.json         # Définition du bloc conteneur
        └── facet-filter/block.json            # Définition du bloc filtre
```

### Flux d'une requête AJAX

```
Utilisateur interagit avec un filtre
        │
        ▼
facets.js — collecte les valeurs des inputs nommés
        │  POST { action, facets[], extra_datas{page, search} }
        ▼
FacetsAjaxHandler::handle()
        │
        ├── parseFacets()
        │       ├── taxonomyFilters  (_search_*)
        │       ├── numericFilters   (*_range)
        │       ├── sort             (order)
        │       └── unknownFacets   (tout le reste → meta custom)
        │
        ├── SearchConfig::getCustomFilters($unknownFacets)
        │
        ├── FacetsSearchService::search()             → hits + pagination + facetDistribution
        ├── FacetsSearchService::getAvailableRanges() → multi-search (quelles plages ont des résultats)
        └── FacetsSearchService::mapSlugsToTaxonomies()
        │
        ▼  JSON { grid HTML, pagination HTML, availableFacets, availableRanges }
        │
facets.js — met à jour x-ref="grid", x-ref="pagination"
          — désactive les options sans résultats
          — met à jour l'URL (pushState)
```

---

## Résolution de problèmes

### Le composant JS ne se déclenche pas

- Vérifier que `Alpine.data('MeilisearchFacets', MeilisearchFacets)` est bien appelé **avant** `Alpine.start()`.
- Vérifier que `data-ajax-action` est présent sur le conteneur `x-data`.

### 404 sur admin-ajax.php

Le composant résout l'URL AJAX dans cet ordre de priorité :
1. `data-ajax-url` sur le conteneur
2. `window.MeilisearchFacetsConfig.ajaxUrl`
3. `window.AOGlobal.ajaxUrl` (localisé par Pollora/thème via `Asset::add(...)->localize('AOGlobal', [...])`)
4. `/wp-admin/admin-ajax.php` (fallback — incorrect si WordPress est dans un sous-dossier)

Si la 404 persiste, forcer l'URL directement sur le conteneur :
```blade
<div
    x-data="MeilisearchFacets()"
    data-ajax-action="myproject_references_facets"
    data-ajax-url="{{ admin_url('admin-ajax.php') }}"
>
```

### Les requêtes AJAX retournent 0 résultats

- Vérifier que `MEILI_INDEX_NAME` correspond à l'index réel dans Meilisearch.
- Vérifier que les documents indexés ont bien `post_type = "{votre_cpt}"` et `post_status = "publish"`.
- Vérifier que `terms` est bien présent dans les documents indexés (relancer `wp meiliscout index --clear`).

### Les filtres numériques ne fonctionnent pas

- Relancer `php artisan meilisearch-facets:configure "App\Search\VotreSearchConfig"` après ajout des plages.
- Vérifier que les documents ont un champ `metas.price` (ou autre) en valeur numérique, pas en string.
- Relancer la commande de synchronisation des metas si nécessaire.

### L'URL ne se met pas à jour

- C'est normal si aucun filtre n'est actif : l'URL revient à son état de base.
- Vérifier que le serveur ne bloque pas les requêtes avec des query strings non reconnus.

### Les facettes disponibles ne se désactivent pas

- Vérifier que les `name` des `<select>` commencent bien par `_search_`.
- Vérifier que `availableFacets` est bien retourné dans la réponse JSON (voir onglet Réseau des DevTools).

### Les filtres meta custom ne fonctionnent pas

**Symptôme :** La requête AJAX retourne HTTP 200 mais la grille ne change pas, ou une erreur Meilisearch indique que l'attribut n'est pas filtrable.

1. **Attribut non filtrable dans Meilisearch :** Dans l'admin Meiliscout, ajouter la clé meta **sans préfixe** (`multisiteProject`, pas `metas.multisiteProject`). Meiliscout ajoute `metas.` automatiquement dans `getIndexSettings()`. Relancer ensuite `wp meiliscout index --clear`.

2. **Champ absent du document Meilisearch :** Pour les champs booléens (Meta Box checkbox), aucune ligne `wp_postmeta` n'est créée lorsque la case est décochée. `gatherMetaKeys()` ne découvre jamais la clé → le champ est absent du document. Utiliser le filtre `meiliscout/post/document` pour forcer la présence du champ avec la valeur `0`.

3. **Seule la première valeur est indexée (champ multi-valeur) :** Meta Box stocke chaque ID sélectionné dans une ligne `wp_postmeta` distincte. `get_post_meta($id, $key, true)` retourne uniquement la première. Utiliser `get_post_meta($id, $key, false)` dans le filtre `meiliscout/post/document` pour récupérer toutes les valeurs sous forme de tableau.

4. **Metas vides lors de l'indexation en temps réel :** `PostSingleIndexer` crée une nouvelle instance de `PostIndexable` dont `$metaKeys` n'est jamais initialisé (seul `getItems()` le fait, lors de l'indexation bulk). `getMetaData()` retourne donc `[]`. Solution : lire les meta via `get_post_meta()` directement dans le hook `meiliscout/post/document`.
