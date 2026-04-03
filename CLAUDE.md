# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

WordPress/Laravel (Pollora) plugin that adds Meilisearch-powered faceted search to post listings. It is designed as a reusable Composer package: consuming projects implement `SearchConfigInterface` per listing type, then register handlers via WordPress hooks.

## Development setup

No build step — PHP and JavaScript are used as-is (no compilation).

```bash
# Install PHP dependencies
composer install

# Local dev installation: copy plugin to WordPress plugins dir and register the
# ServiceProvider manually in bootstrap/providers.php (see README.md for details)
```

No test suite exists in this project.

## Architecture

### Core request flow

```
Alpine.js form → AJAX POST → FacetsAjaxHandler → FacetsSearchService → MeilisearchClient
                                                                               ↓
Alpine.js updates DOM ← JSON response (grid HTML + pagination HTML + facet counts)
```

### Configuration-driven design

Each listing type has its own `SearchConfig` class implementing `SearchConfigInterface` (with defaults in `AbstractSearchConfig`). A config defines: post type, filterable taxonomies, numeric range groups, AJAX action name, and how to render hits and pagination.

Projects register configs at WordPress `init`:
```php
FacetsAjaxHandler::register(new MySearchConfig());          // registers AJAX handler
FacetedListingRegistry::register('post-type', new MySearchConfig()); // for Gutenberg blocks
```

### Key classes

| Class | Role |
|---|---|
| `FacetsAjaxHandler` | AJAX entry point — sanitizes/parses form data, orchestrates the search, returns JSON |
| `FacetsSearchService` | Builds Meilisearch filter strings and calls the client |
| `MeilisearchClient` | Low-level cURL HTTP client (no SDK dependency) |
| `AbstractSearchConfig` | Base config with sensible defaults; extend this |
| `FacetedListingBlock` / `FacetFilterBlock` | Gutenberg blocks (container + child filter) via MetaBox |
| `FacetedListingRegistry` | Static registry: CPT slug → SearchConfig, used by Gutenberg blocks |
| `ConfigureIndexCommand` | Artisan command to push filterable/sortable attributes to Meilisearch |
| `QueryIntegration` | Converts URL query params → `WP_Query` args for initial (non-AJAX) page load |

### Input naming conventions (JavaScript ↔ PHP)

The Alpine.js component (`resources/js/facets.js`) and `FacetsAjaxHandler` share these conventions:

- `_search_{taxonomy}` → taxonomy filter (select/radio)
- `{group}_range` → numeric range filter (checkbox group; value = `min:max`)
- `search_query` → text search
- `order` → sort field
- Any other name → passed to `SearchConfig::getCustomFilters()` as unknown facets

### Gutenberg blocks

Two blocks work in tandem (registered via MetaBox):
- **`meta-box/faceted-listing`** — container block; attributes: `postType`, `hitsPerPage`, `gridColumns`
- **`meta-box/facet-filter`** — child block (restricted to parent); attributes: `displayType`, `dataType`, `source`, `inputName`, `label`, `placeholder`

`resources/js/blocks/editor.js` wraps the container block with an InnerBlocks HOC so child filter blocks can be inserted in the editor.

### Meilisearch document structure

Documents must be indexed with:
- `terms`: array of `{taxonomy, slug, name}` objects (for taxonomy filters)
- `metas`: object of numeric/boolean meta values (e.g. `metas.price`, `metas.multisiteProject`)
- `post_type` and `post_status` fields

## Environment variables

```
MEILI_HOST=http://localhost:7700
MEILI_KEY=your_master_key
MEILI_INDEX_NAME=posts
MEILI_MATCHING_STRATEGY=last
```

## Artisan command

```bash
# Push filterable/sortable attributes + ranking rules to Meilisearch for a given config
php artisan meilisearch-facets:configure "App\Search\YourSearchConfig"
```
