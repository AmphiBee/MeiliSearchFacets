<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\DTO;

/**
 * Représente une plage numérique filtrable (ex: prix, durée, surface...).
 *
 * Usage :
 *   NumericRange::between('1000-2000', 'metas.price', 1000, 2000)
 *   NumericRange::above('4000+', 'metas.price', 4000)
 */
final readonly class NumericRange
{
    private function __construct(
        /** Identifiant unique de la plage, utilisé dans les paramètres GET/POST (ex: '1000-2000', '4000+'). */
        public string $key,
        /** Champ Meilisearch ciblé (ex: 'metas.price', 'metas.days'). */
        public string $field,
        public int $min,
        /** null signifie "sans borne supérieure" (ex: 4000+). */
        public ?int $max,
    ) {}

    /**
     * Plage fermée : min <= valeur < max.
     */
    public static function between(string $key, string $field, int $min, int $max): self
    {
        return new self($key, $field, $min, $max);
    }

    /**
     * Plage ouverte : valeur >= min.
     */
    public static function above(string $key, string $field, int $min): self
    {
        return new self($key, $field, $min, null);
    }

    /**
     * Génère le filtre Meilisearch correspondant.
     * Ex: "(metas.price >= 1000 AND metas.price < 2000)"
     */
    public function toMeilisearchFilter(): string
    {
        if ($this->max === null) {
            return "{$this->field} >= {$this->min}";
        }

        return "({$this->field} >= {$this->min} AND {$this->field} < {$this->max})";
    }

    /**
     * Génère la condition WP_Query meta_query correspondante.
     * Utilisée pour le chargement initial de page (sans AJAX).
     */
    public function toWpMetaCondition(): array
    {
        $metaKey = $this->extractMetaKey();

        if ($this->max === null) {
            return [
                'key'     => $metaKey,
                'value'   => $this->min,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        }

        return [
            'key'     => $metaKey,
            'value'   => [$this->min, $this->max - 1],
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ];
    }

    /**
     * Extrait la meta key depuis le chemin de champ Meilisearch.
     * Ex: 'metas.price' → 'price'
     */
    private function extractMetaKey(): string
    {
        $parts = explode('.', $this->field);

        return end($parts);
    }
}
