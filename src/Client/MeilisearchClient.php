<?php

declare(strict_types=1);

namespace AmphiBee\MeilisearchFacets\Client;

/**
 * Client HTTP bas niveau pour l'API Meilisearch.
 * N'utilise que cURL, sans dépendance externe.
 */
class MeilisearchClient
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $key,
    ) {}

    /**
     * Effectue une recherche sur un index.
     *
     * @param  array  $params  Paramètres de recherche Meilisearch (q, filter, facets, sort, etc.)
     * @return array Réponse brute Meilisearch (hits, totalHits, totalPages, facetDistribution, etc.)
     */
    public function search(string $index, array $params): array
    {
        return $this->request('POST', "/indexes/{$index}/search", $params);
    }

    /**
     * Exécute plusieurs requêtes de recherche en un seul appel HTTP (multi-search).
     * Utilisé pour déterminer quelles plages numériques ont des résultats.
     *
     * @param  array  $queries  Tableau de requêtes, chacune avec 'indexUid' et les paramètres habituels.
     * @return array Tableau de résultats dans le même ordre que les requêtes.
     */
    public function multiSearch(array $queries): array
    {
        $response = $this->request('POST', '/multi-search', ['queries' => $queries]);

        return $response['results'] ?? [];
    }

    /**
     * Récupère la valeur actuelle d'un paramètre de settings d'un index.
     *
     * @param  string  $setting  Ex: 'filterable-attributes', 'sortable-attributes'
     */
    public function getSettings(string $index, string $setting): array
    {
        return $this->request('GET', "/indexes/{$index}/settings/{$setting}");
    }

    /**
     * Met à jour un paramètre de settings d'un index (remplace la valeur courante).
     */
    public function updateSettings(string $index, string $setting, array $value): bool
    {
        $this->request('PUT', "/indexes/{$index}/settings/{$setting}", $value);

        return true;
    }

    /**
     * Met à jour des documents dans l'index (merge partiel).
     * Meilisearch utilise le champ 'ID' comme clé primaire par défaut.
     *
     * @param  array  $documents  Tableau de documents. Chaque document doit avoir un champ 'ID'.
     */
    public function updateDocuments(string $index, array $documents): bool
    {
        $this->request('PUT', "/indexes/{$index}/documents", $documents);

        return true;
    }

    /**
     * Effectue une requête HTTP vers l'API Meilisearch.
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->url . $path);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = ['Content-Type: application/json'];
        if ($this->key) {
            $headers[] = "Authorization: Bearer {$this->key}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method !== 'GET' && ! empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError) {
            throw new \RuntimeException("Meilisearch cURL error: {$curlError}");
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($response, true) ?? [];
            $message = $decoded['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Meilisearch API error on {$method} {$path}: {$message}");
        }

        return json_decode($response, true) ?? [];
    }
}
