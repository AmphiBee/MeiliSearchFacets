# Meilisearch Facets

Plugin Composer réutilisable pour ajouter un système de **filtres/facettes Meilisearch** sur n'importe quel listing d'un projet Pollora (Laravel + WordPress).

---

## Table des matières

1. [Prérequis](#prérequis)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Créer un listing facetté en 5 étapes](#créer-un-listing-facetté-en-5-étapes)
   - [Étape 1 — Implémenter SearchConfigInterface](#étape-1--implémenter-searchconfiginterface)
   - [Étape 2 — Enregistrer le handler AJAX (Hook Pollora)](#étape-2--enregistrer-le-handler-ajax-hook-pollora)
   - [Étape 3 — Configurer l'index Meilisearch](#étape-3--configurer-lindex-meilisearch)
   - [Étape 4 — Construire le template Blade](#étape-4--construire-le-template-blade)
   - [Étape 5 — Initialiser le composant Alpine.js](#étape-5--initialiser-le-composant-alpinejs)
5. [Référence — SearchConfigInterface](#référence--searchconfiginterface)
6. [Référence — Conventions de nommage des inputs](#référence--conventions-de-nommage-des-inputs)
7. [Cas d'usage avancés](#cas-dusage-avancés)
   - [Filtres numériques (prix, durée...)](#filtres-numériques-prix-durée)
   - [Filtres meta custom (booléens, relationnels)](#filtres-meta-custom-booléens-relationnels)
   - [Listing sans filtres numériques](#listing-sans-filtres-numériques)
   - [Pagination personnalisée](#pagination-personnalisée)
   - [Plusieurs listings sur un même projet](#plusieurs-listings-sur-un-même-projet)
8. [Architecture interne](#architecture-interne)
9. [Résolution de problèmes](#résolution-de-problèmes)

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

## Créer un listing facetté en 5 étapes

Voici comment ajouter un listing facetté de bout en bout. L'exemple utilise un CPT `product` avec une taxonomie `product-category` et un filtre de prix.

---

### Étape 1 — Implémenter SearchConfigInterface

Créer une classe dans `app/Search/` qui étend `AbstractSearchConfig`.

```php
<?php
// app/Search/ProductSearchConfig.php

declare(strict_types=1);

namespace App\Search;

use AmphiBee\MeilisearchFacets\Config\AbstractSearchConfig;
use AmphiBee\MeilisearchFacets\DTO\NumericRange;

class ProductSearchConfig extends AbstractSearchConfig
{
    /**
     * Nom unique de l'action AJAX pour ce listing.
     * Doit être unique dans tout le projet.
     */
    public function getAjaxAction(): string
    {
        return 'myproject_products_facets';
    }

    /**
     * Slug du CPT WordPress ciblé.
     */
    public function getPostType(): string
    {
        return 'product';
    }

    /**
     * Taxonomies utilisables comme facettes.
     * Utiliser les slugs WordPress exacts.
     */
    public function getFilterableTaxonomies(): array
    {
        return ['product-category', 'product-brand'];
    }

    /**
     * Plages numériques (optionnel).
     * Supprimer cette méthode si aucun filtre numérique n'est nécessaire.
     *
     * La clé du tableau ('price_range') sera le name de l'input HTML.
     * Le champ Meilisearch ('metas.price') doit être dans l'index.
     */
    public function getNumericRangeGroups(): array
    {
        return [
            'price_range' => [
                NumericRange::between('0-50',    'metas.price', 0,   50),
                NumericRange::between('50-100',  'metas.price', 50,  100),
                NumericRange::between('100-200', 'metas.price', 100, 200),
                NumericRange::above('200+',      'metas.price', 200),
            ],
        ];
    }

    /**
     * Nombre de résultats par page.
     */
    public function getHitsPerPage(): int
    {
        return 9; // grille 3×3
    }

    /**
     * Tri par défaut.
     * Laisser vide pour utiliser le ranking natif Meilisearch.
     */
    public function getDefaultSort(): array
    {
        return ['post_title:asc'];
    }

    /**
     * Rendu d'une carte produit.
     * Appelé pour chaque résultat lors d'une requête AJAX.
     * $hit['ID'] contient l'ID WordPress du post.
     */
    public function renderHit(array $hit): string
    {
        return view('components.cards.product', ['id' => $hit['ID']])->render();
    }
}
```

**Points importants :**
- `getAjaxAction()` doit être **unique** par listing et par projet.
- `getPostType()` doit correspondre exactement au slug du CPT WordPress.
- Les clés de `getNumericRangeGroups()` doivent se terminer par `_range` (convention du composant JS).
- Le champ Meilisearch (`metas.price`) doit exister dans l'index (voir Étape 3).

---

### Étape 2 — Enregistrer le handler AJAX (Hook Pollora)

Créer un Hook Pollora dans `app/Cms/Hooks/Search/` :

```php
<?php
// app/Cms/Hooks/Search/ProductFacetsHook.php

declare(strict_types=1);

namespace App\Cms\Hooks\Search;

use AmphiBee\MeilisearchFacets\Ajax\FacetsAjaxHandler;
use App\Search\ProductSearchConfig;
use Pollora\Attributes\Action;

class ProductFacetsHook
{
    /**
     * Enregistre l'action AJAX WordPress pour ce listing.
     * Déclenché sur 'init' pour que les actions wp_ajax_* soient disponibles.
     */
    #[Action('init')]
    public function registerAjaxHandler(): void
    {
        FacetsAjaxHandler::register(new ProductSearchConfig());
    }
}
```

> Pollora découvre automatiquement les classes dans `app/Cms/Hooks/` grâce à son système de découverte basé sur les attributs PHP. Aucune déclaration supplémentaire n'est nécessaire.

**Ajout optionnel — support des URLs partageables avec filtres pré-appliqués :**

Si vous souhaitez qu'un filtre actif dans l'URL (ex: `?_search_product-category=tshirt`) soit pré-appliqué dès le chargement serveur (SEO, partage de lien), ajouter le hook suivant :

```php
use AmphiBee\MeilisearchFacets\Hooks\QueryIntegration;
use Pollora\Attributes\Filter;

// Dans ProductFacetsHook :

/**
 * Injecte les paramètres $_GET dans les args WP_Query du chargement initial.
 * Nommer le filtre de façon unique par listing : '{projet}_get_{cpt}s_query_args'.
 */
#[Filter('myproject_get_products_query_args', priority: 10)]
public function buildQueryArgs(array $args): array
{
    return QueryIntegration::apply($args, new ProductSearchConfig());
}
```

Ce filtre est alors appliqué dans le template Blade via `apply_filters('myproject_get_products_query_args', [...])` avant l'instanciation de `WP_Query`. Si le chargement initial est entièrement délégué à Alpine (grille vide, premier `refresh()` au `init`), ce hook n'est pas nécessaire.

---

### Étape 3 — Configurer l'index Meilisearch

Lancer la commande Artisan fournie par le plugin pour configurer automatiquement l'index :

```bash
php artisan meilisearch-facets:configure "App\Search\ProductSearchConfig"
```

Cette commande configure dans l'index Meilisearch :
- **Attributs filtrables** : `terms.taxonomy`, `terms.slug`, `post_type`, `post_status`, + les champs numériques déclarés (`metas.price`)
- **Attributs triables** : `post_title`, `post_date`, + les champs numériques
- **Ranking rules** : `sort` en premier (pour que le tri explicite prime sur la pertinence)

> Relancer cette commande à chaque ajout de nouveau champ numérique.

Si les metas numériques (`metas.price`) ne sont pas encore dans l'index, il faut les y pousser. Voir [Pousser des metas numériques dans l'index](#pousser-des-metas-numériques-dans-lindex).

---

### Étape 4 — Construire le template Blade

Le composant JS s'appuie sur une structure HTML précise. Voici un template minimal :

```blade
{{-- themes/{theme}/resources/views/blocks/listings/products.blade.php --}}

{{--
    Conteneur principal du composant MeilisearchFacets.
    data-ajax-action : correspond à getAjaxAction() dans la config.
    x-cloak         : masque la section jusqu'à ce qu'Alpine soit prêt.
--}}
<section
    x-data="MeilisearchFacets()"
    data-ajax-action="myproject_products_facets"
    x-cloak
>
    {{-- ===== FILTRES ===== --}}
    {{--
        Convention de nommage des inputs :
        - Taxonomie : name="_search_{slug-taxonomie}"
        - Plage num. : name="{clé_groupe}" (ex: price_range)
        - Recherche   : name="search_query"
        - Tri         : name="order"
    --}}

    {{-- Filtre par taxonomie --}}
    <select name="_search_product-category">
        <option value="">Toutes les catégories</option>
        @foreach(get_terms(['taxonomy' => 'product-category', 'hide_empty' => true]) as $term)
            <option value="{{ $term->slug }}">{{ $term->name }}</option>
        @endforeach
    </select>

    {{-- Filtre numérique par plage de prix (checkboxes) --}}
    <fieldset>
        <legend>Prix</legend>
        <label><input type="checkbox" name="price_range" value="0-50">   Moins de 50 €</label>
        <label><input type="checkbox" name="price_range" value="50-100"> 50 – 100 €</label>
        <label><input type="checkbox" name="price_range" value="100-200">100 – 200 €</label>
        <label><input type="checkbox" name="price_range" value="200+">   200 € et plus</label>
    </fieldset>

    {{-- Recherche textuelle --}}
    <input type="search" name="search_query" placeholder="Rechercher...">

    {{-- ===== GRILLE ===== --}}
    {{-- x-ref="grid" est obligatoire. Le contenu est injecté par AJAX au init. --}}
    <div class="grid" x-ref="grid">
        {{-- Rempli dynamiquement par MeilisearchFacets via AJAX --}}
    </div>

    {{-- ===== PAGINATION ===== --}}
    {{-- x-ref="pagination" est obligatoire --}}
    <div x-ref="pagination"></div>
</section>
```

**Points importants :**
- `x-data="MeilisearchFacets()"` initialise le composant Alpine. Au `init`, il déclenche automatiquement une première requête AJAX pour remplir la grille.
- `data-ajax-action` doit correspondre exactement à `getAjaxAction()`.
- `x-ref="grid"` et `x-ref="pagination"` sont requis par le composant JS — il injecte directement le HTML retourné par le serveur dans ces éléments.
- `x-cloak` masque la section jusqu'à ce qu'Alpine soit initialisé, évitant un flash de contenu vide.

---

### Étape 5 — Initialiser le composant Alpine.js

Importer et enregistrer le composant dans le fichier JS principal du thème :

```javascript
// themes/{theme}/resources/assets/js/frontend/app.js

import Alpine from 'alpinejs';
import MeilisearchFacets from '../../../../public/content/plugins/meilisearch-facets/resources/js/facets.js';

Alpine.data('MeilisearchFacets', MeilisearchFacets);

Alpine.start();
```

> Une fois le plugin installé via Composer dans `vendor/`, adapter le chemin d'import :
> ```javascript
> import MeilisearchFacets from '../../../vendor/amphibee/meilisearch-facets/resources/js/facets.js';
> ```

Recompiler les assets :

```bash
npm run build
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
| `_search_{taxonomy}` | Filtre par taxonomie | `_search_product-category` |
| `{groupe}_range` | Filtre par plage numérique | `price_range`, `duration_range` |
| `search_query` | Recherche textuelle libre | — |
| `order` | Tri (valeurs: `asc`, `desc`) | — |
| Tout autre `name` | Meta custom → transmis à `getCustomFilters()` | `multisiteProject`, `related_solutions` |

> Les clés des groupes dans `getNumericRangeGroups()` doivent correspondre exactement aux `name` des inputs HTML.

**Comportement de la recherche textuelle :** l'input `name="search_query"` doit être de type `search` ou `text`. Le composant écoute l'événement `keydown` + `Enter` (cross-browser, y compris Firefox) et l'événement `input` lorsque le champ est vidé via le bouton ✕.

---

## Cas d'usage avancés

### Filtres numériques (prix, durée...)

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

        // Envoi par lots de 100
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

#### 3. Inputs HTML correspondants

```blade
{{-- price_range doit correspondre exactement à la clé du groupe --}}
<label><input type="checkbox" name="price_range" value="0-50">   0 – 50 €</label>
<label><input type="checkbox" name="price_range" value="50-100"> 50 – 100 €</label>
<label><input type="checkbox" name="price_range" value="200+">   200 € et plus</label>

<label><input type="checkbox" name="duration_range" value="1-7">  1 – 7 jours</label>
<label><input type="checkbox" name="duration_range" value="8-14"> 8 – 14 jours</label>
<label><input type="checkbox" name="duration_range" value="15+">  15 jours et plus</label>
```

---

### Filtres meta custom (booléens, relationnels)

Pour les champs meta qui ne sont ni des taxonomies ni des plages numériques (ex: case à cocher booléenne, sélection d'un post lié), il faut implémenter `getCustomFilters()`.

#### Principe

Le composant Alpine collecte **tous** les inputs nommés dans le conteneur `x-data`. Les valeurs qui ne correspondent à aucune convention reconnue (`_search_*`, `*_range`, `search_query`, `order`) sont transmises à `getCustomFilters()` dans le tableau `$unknownFacets`.

#### 1. Déclarer les filtres dans la config

```php
public function getCustomFilters(array $unknownFacets): array
{
    $filters = [];

    // Booléen : coché = valeur "1", non coché = absent du tableau
    if (! empty($unknownFacets['multisiteProject'])) {
        $filters[] = 'metas.multisiteProject = 1';
    }

    // Relationnel : valeur = ID du post lié
    if (! empty($unknownFacets['related_solutions'])) {
        $id = (int) $unknownFacets['related_solutions'];
        $filters[] = "metas.related_solutions = {$id}";
    }

    return $filters;
}
```

Les chaînes retournées sont des expressions de filtre Meilisearch valides, ajoutées directement à la clause `filter` de la requête.

#### 2. Inputs HTML correspondants

```blade
{{-- Booléen : name sans préfixe, value="1" --}}
<input type="checkbox" name="multisiteProject" value="1">

{{-- Relationnel : name sans préfixe, value = ID --}}
<select name="related_solutions">
    <option value="">Toutes les solutions</option>
    @foreach(get_posts(['post_type' => 'solution', 'posts_per_page' => -1]) as $solution)
        <option value="{{ $solution->ID }}">{{ $solution->post_title }}</option>
    @endforeach
</select>
```

#### 3. S'assurer que les metas sont bien indexées

Meiliscout indexe les metas via `get_post_meta($id, $key, true)`, ce qui retourne uniquement la première valeur pour les champs multi-valeur. Pour les champs stockant plusieurs IDs (Meta Box "Multiple Post"), utiliser le filtre `meiliscout/post/document` pour corriger le document avant indexation :

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

#### 4. Configurer les attributs filtrables dans Meiliscout

Dans l'admin Meiliscout, ajouter les clés **sans préfixe** (Meiliscout ajoute `metas.` automatiquement) :
- `multisiteProject`
- `related_solutions`

Puis relancer l'indexation :

```bash
wp meiliscout index --clear
```

---

### Listing sans filtres numériques

Si votre listing filtre uniquement par taxonomies, il n'y a rien à faire : `getNumericRangeGroups()` retourne `[]` par défaut dans `AbstractSearchConfig`.

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
        return view('components.cards.post', ['id' => $hit['ID']])->render();
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
    ReferenceSearchConfig.php   → action: 'tds_references_facets'
    SolutionSearchConfig.php    → action: 'tds_solutions_facets'
    ArticleSearchConfig.php     → action: 'tds_articles_facets'

app/Cms/Hooks/Search/
    ReferenceFacetsHook.php
    SolutionFacetsHook.php
    ArticleFacetsHook.php
```

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
│   │   └── FacetsAjaxHandler.php              # Handler WP AJAX générique (grille + drawer)
│   ├── Console/
│   │   └── ConfigureIndexCommand.php          # php artisan meilisearch-facets:configure
│   └── Hooks/
│       └── QueryIntegration.php               # Injection $_GET → WP_Query (chargement initial)
├── config/
│   └── meilisearch-facets.php                 # Config publiable (url, key, index, strategy)
└── resources/js/
    └── facets.js                              # Composant Alpine.js générique
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
        │       └── retourne des chaînes de filtre Meilisearch brutes
        │
        ├── FacetsSearchService::search()             → hits + pagination + facetDistribution
        │       └── buildFilters() applique customFilters directement dans la clause filter
        ├── FacetsSearchService::getAvailableRanges() → multi-search (quelles plages ont des résultats)
        └── FacetsSearchService::mapSlugsToTaxonomies() → mapping slug → taxonomie
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
    data-ajax-action="tds_references_facets"
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
- Relancer la commande de synchronisation des metas (`php artisan search:sync-products`).

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

4. **Metas vides lors de l'indexation en temps réel :** `PostSingleIndexer` crée une nouvelle instance de `PostIndexable` dont `$metaKeys` n'est jamais initialisé (seul `getItems()` le fait, lors de l'indexation bulk). `getMetaData()` retourne donc `[]`. Solution : lire les meta via `get_post_meta()` directement dans le hook `meiliscout/post/document`, indépendamment de `$document['metas']`.
