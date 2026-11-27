<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Tests\Fixtures;

use AmphiBee\MeilisearchFacets\Config\AbstractSearchConfig;

class ProduitsSearchConfig extends AbstractSearchConfig
{
    public function getAjaxAction(): string
    {
        return 'produits';
    }

    public function getPostType(): string
    {
        return 'produit';
    }

    public function getIndex(): string
    {
        return 'produits';
    }

    public function getFilterableTaxonomies(): array
    {
        return [];
    }

    public function getFilterableAttributes(): array
    {
        return ['post_type'];
    }

    public function renderHit(array $hit): string
    {
        return '';
    }
}
