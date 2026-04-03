# Guide — Listings facettés via blocs Gutenberg

Ce guide explique comment créer et configurer un listing filtré par Meilisearch entièrement depuis l'éditeur Gutenberg, sans toucher au code Blade ou PHP de template.

---

## Vue d'ensemble

Le système repose sur **deux blocs Gutenberg complémentaires** :

| Bloc | Nom interne | Rôle |
|---|---|---|
| **Listing facetté** | `meta-box/faceted-listing` | Bloc conteneur — définit le CPT, la grille et l'action AJAX |
| **Filtre de facette** | `meta-box/facet-filter` | Bloc enfant — définit un filtre (select, radio, checkbox) |

Les blocs "Filtre de facette" ne peuvent être insérés **qu'à l'intérieur** d'un bloc "Listing facetté". Chaque filtre rendu par un bloc enfant devient automatiquement un `<select>`, un groupe de radios ou des checkboxes dans le panneau de filtres.

---

## Fonctionnement général

```
┌─ Bloc "Listing facetté" ──────────────────────────────────────────────┐
│  Sidebar : postType=reference, gridColumns=3, hitsPerPage=12          │
│                                                                        │
│  ┌─ Bloc "Filtre de facette" ──────────────────┐                      │
│  │  Sidebar : displayType=select               │                      │
│  │             dataType=taxonomy               │  → <select name="_search_activity-sector">
│  │             source=activity-sector          │                      │
│  │             inputName=_search_activity-sector│                     │
│  └────────────────────────────────────────────┘                      │
│                                                                        │
│  ┌─ Bloc "Filtre de facette" ──────────────────┐                      │
│  │  Sidebar : displayType=select               │                      │
│  │             dataType=post_type              │  → <select name="related_solutions">
│  │             source=solution                 │                      │
│  │             inputName=related_solutions     │                      │
│  └────────────────────────────────────────────┘                      │
│                                                                        │
│  [Grille de résultats — remplie via AJAX]                             │
│  [Pagination]                                                         │
└───────────────────────────────────────────────────────────────────────┘
```

Au rendu, MetaBox remplace les blocs enfants par leur HTML (les inputs de filtre), qui sont placés à l'intérieur du panneau de filtres (drawer mobile + inline desktop).

---

## Étape 1 — Prérequis : enregistrer un SearchConfig

Avant d'utiliser le bloc, le CPT doit avoir une `SearchConfig` PHP associée et être enregistré dans le `FacetedListingRegistry`.

### 1.1 — Créer la SearchConfig

```php
// app/Search/MonNouveauSearchConfig.php
namespace App\Search;

use AmphiBee\MeilisearchFacets\Config\AbstractSearchConfig;

class MonNouveauSearchConfig extends AbstractSearchConfig
{
    public function getAjaxAction(): string
    {
        return 'tds_mon_nouveau_facets'; // nom unique pour ce listing
    }

    public function getPostType(): string
    {
        return 'mon-post-type';
    }

    public function getFilterableTaxonomies(): array
    {
        return ['ma-taxonomie'];
    }

    public function getHitsPerPage(): int
    {
        return 12; // sera surchargé par le bloc Gutenberg si configuré
    }

    public function renderHit(array $hit): string
    {
        // Retourne le HTML d'une carte de résultat
        return view('components.cards.ma-carte', ['id' => $hit['ID']])->render();
    }
}
```

### 1.2 — Enregistrer le handler AJAX et le registre

```php
// app/Cms/Hooks/Search/MonNouveauFacetsHook.php
namespace App\Cms\Hooks\Search;

use AmphiBee\MeilisearchFacets\Ajax\FacetsAjaxHandler;
use App\Helpers\FacetedListingRegistry;
use App\Search\MonNouveauSearchConfig;
use Pollora\Attributes\Action;

class MonNouveauFacetsHook
{
    #[Action('init')]
    public function registerAjaxHandler(): void
    {
        if (! in_array('meilisearch-facets/meilisearch-facets.php', (array) get_option('active_plugins', []), true)) {
            return;
        }

        $config = new MonNouveauSearchConfig();
        FacetsAjaxHandler::register($config);
        FacetedListingRegistry::register('mon-post-type', $config); // ← clé = slug CPT
    }
}
```

> **Important** : La clé passée à `FacetedListingRegistry::register()` doit correspondre exactement au slug CPT que vous saisirez dans le champ "Type de contenu" du bloc Gutenberg.

### 1.3 — Configurer l'index Meilisearch (une fois)

```bash
ddev ssh
php artisan meilisearch-facets:configure "App\Search\MonNouveauSearchConfig"
```

---

## Étape 2 — Configurer le bloc "Listing facetté"

1. Dans Gutenberg, insérez le bloc **"Listing facetté (Meilisearch)"**
2. Dans la sidebar à droite, renseignez :

| Champ | Description | Exemple |
|---|---|---|
| **Type de contenu (CPT slug)** | Slug du CPT enregistré dans `FacetedListingRegistry` | `reference` |
| **Colonnes de la grille** | Nombre de colonnes sur desktop | `3` |
| **Résultats par page** | Nombre de cartes affichées par page | `12` |

---

## Étape 3 — Ajouter des filtres (blocs enfants)

À l'intérieur du bloc "Listing facetté", cliquez sur **"+"** pour ajouter des blocs. Seul le bloc **"Filtre de facette"** est proposé.

Configurez chaque filtre dans la sidebar :

### Champs de configuration

| Champ | Description |
|---|---|
| **Type d'affichage** | Comment les options sont rendues dans l'UI |
| **Type de données** | D'où viennent les options du filtre |
| **Source** | Slug de la taxonomie ou du CPT |
| **Nom de l'input** | Nom HTML — doit respecter une convention (voir ci-dessous) |
| **Label** | Intitulé visible dans l'interface |
| **Texte de l'option vide** | Texte du "Tous" / option vide |

---

## Les 3 types d'affichage

### `select` — Liste déroulante
Options affichées dans un `<select>`. Sélection unique.
Convient aux taxonomies avec beaucoup de termes.

### `radio` — Boutons pilules
Options affichées en rangée horizontale. Sélection unique.
Inclut automatiquement un bouton "Tous".
Convient aux taxonomies avec peu de termes (~5).

### `checkbox` — Cases à cocher
Options empilées verticalement. Sélection multiple.
Les valeurs sont envoyées en tableau (`name[]`).

---

## Les 3 types de données

### `taxonomy` — Taxonomie WordPress
Charge les termes d'une taxonomie WordPress via `get_terms()`.
**Source** = slug de la taxonomie (ex: `activity-sector`, `category`).
**Nom de l'input** = doit commencer par `_search_` suivi du slug de la taxonomie.

Exemple :
```
dataType  : taxonomy
source    : activity-sector
inputName : _search_activity-sector
```

### `post_type` — Type de contenu
Charge les posts d'un CPT via `get_posts()`. La valeur envoyée est l'ID du post.
**Source** = slug du CPT (ex: `solution`, `need`).
**Nom de l'input** = nom du champ méta tel que stocké dans Meilisearch.

Exemple :
```
dataType  : post_type
source    : solution
inputName : related_solutions
```

> Ce type de filtre nécessite que `getCustomFilters()` de la SearchConfig gère ce nom. Voir l'exemple plus bas.

### `meta_boolean` — Méta booléen
Affiche une case à cocher unique. Quand cochée, envoie la valeur `1`.
**Source** = laisser vide.
**Nom de l'input** = nom du champ méta.

Exemple :
```
dataType  : meta_boolean
inputName : multisiteProject
label     : Projets multi-sites uniquement
```

---

## Convention de nommage des inputs

| Pattern | Interprétation côté PHP | Exemple |
|---|---|---|
| `_search_{taxonomy}` | Filtre taxonomique automatique | `_search_activity-sector` |
| `{nom}_range` | Filtre numérique (plage) | `price_range` |
| `search_query` | Recherche plein texte | — |
| `order` | Tri des résultats | — |
| **Tout autre nom** | Filtre custom → `getCustomFilters()` | `related_solutions`, `multisiteProject` |

---

## Côté code : gestion des filtres custom

Les filtres `post_type` et `meta_boolean` sont des filtres "custom" : ils arrivent dans `getCustomFilters()` de la SearchConfig et doivent y être transformés en filtres Meilisearch.

```php
// Dans votre SearchConfig
public function getCustomFilters(array $unknownFacets): array
{
    $filters = [];

    // Filtre "related_solutions" (ID de post)
    if (! empty($unknownFacets['related_solutions'])) {
        $id = (int) $unknownFacets['related_solutions'];
        $filters[] = "metas.related_solutions = {$id}";
    }

    // Filtre "multisiteProject" (booléen)
    if (! empty($unknownFacets['multisiteProject'])) {
        $filters[] = 'metas.multisiteProject = 1';
    }

    return $filters;
}
```

> Si vous ajoutez un nouveau filtre `post_type` ou `meta_boolean` dans Gutenberg, pensez à ajouter le cas correspondant ici.

---

## Exemple complet : listing de réalisations avec 3 filtres

### Configuration du bloc conteneur
```
Type de contenu  : reference
Colonnes         : 3
Résultats/page   : 12
```

### Filtre 1 — Secteur d'activité (radio)
```
Type d'affichage : radio
Type de données  : taxonomy
Source           : activity-sector
Nom de l'input   : _search_activity-sector
Label            : Secteurs d'activité
Texte vide       : Tous les secteurs
```

### Filtre 2 — Solutions associées (select)
```
Type d'affichage : select
Type de données  : post_type
Source           : solution
Nom de l'input   : related_solutions
Label            : Solutions
Texte vide       : Toutes les solutions
```

### Filtre 3 — Projets multi-sites (checkbox unique)
```
Type d'affichage : checkbox
Type de données  : meta_boolean
Source           : (vide)
Nom de l'input   : multisiteProject
Label            : Projets multi-sites uniquement
```

### Résultat
- Panneau de filtres avec 3 entrées
- Grille 3 colonnes, 12 résultats/page
- Les filtres triggent Meilisearch à chaque changement via AJAX
- L'URL est mise à jour (filtres partageables, retour navigateur)

---

## Récapitulatif des fichiers

| Fichier | Rôle |
|---|---|
| `app/Search/*SearchConfig.php` | Logique Meilisearch (renderHit, filtres custom) |
| `app/Cms/Hooks/Search/*FacetsHook.php` | Enregistrement AJAX + registre |
| `app/Helpers/FacetedListingRegistry.php` | Carte CPT → SearchConfig |
| `themes/.../blocks/faceted-listing/` | Bloc conteneur (block.json + render.php + Blade) |
| `themes/.../blocks/facet-filter/` | Bloc filtre (block.json + render.php + Blade) |
| `themes/.../Hooks/Blocks/FacetedListing.php` | Enregistrement WordPress du bloc conteneur |
| `themes/.../Hooks/Blocks/FacetFilter.php` | Enregistrement WordPress du bloc filtre |
