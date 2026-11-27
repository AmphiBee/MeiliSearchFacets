<?php

/**
 * Double de `Pollora\MeiliScout\Config\Config` tel qu'il se comporte hors WordPress,
 * sans variable d'environnement : il tombe sur `get_option()`, qui n'existe pas.
 */

declare(strict_types=1);

namespace Pollora\MeiliScout\Config;

class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return get_option('meiliscout/' . $key, $default);
    }
}
