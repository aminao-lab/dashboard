<?php
require_once __DIR__ . '/config.php';

class SupabaseClient
{
    private $url;
    private $key;
    private $headers;

    public function __construct()
    {
        $this->url = SUPABASE_URL . '/rest/v1';
        $this->key = SUPABASE_SERVICE_KEY;
        $this->headers = [
            'apikey: ' . $this->key,
            'Authorization: Bearer ' . $this->key,
            'Content-Type: application/json',
            'Prefer: return=representation'
        ];
    }

    private function configureCurl($ch)
    {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $isLocal = (
            php_sapi_name() === 'cli' ||
            !isset($_SERVER['SERVER_NAME']) ||
            $_SERVER['SERVER_NAME'] === 'localhost' ||
            $_SERVER['SERVER_NAME'] === '127.0.0.1' ||
            strpos($_SERVER['SERVER_NAME'], '.local') !== false
        );

        if ($isLocal) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
    }

    public function upsert($table, $data, $onConflict = null)
    {
        $url = "{$this->url}/{$table}";
        if ($onConflict) {
            $url .= "?on_conflict={$onConflict}";
        }

        $headers = $this->headers;
        if ($onConflict) {
            $headers = array_filter($headers, function ($h) {
                return strpos($h, 'Prefer:') !== 0;
            });
            $headers[] = 'Prefer: return=representation,resolution=merge-duplicates';
        }

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values($headers));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 400 && $http_code !== 409) {
            error_log("❌ Supabase upsert error ($http_code): $response");
            return false;
        }

        return json_decode($response, true);
    }

    public function select($table, $select = '*', $filters = [], $options = [])
    {
        $url = "{$this->url}/{$table}?select={$select}";

        foreach ($filters as $key => $value) {
            $url .= "&{$key}={$value}";
        }

        if (isset($options['limit'])) {
            $url .= "&limit={$options['limit']}";
        }
        if (isset($options['order'])) {
            $url .= "&order={$options['order']}";
        }
        if (isset($options['offset'])) {
            $url .= "&offset={$options['offset']}";
        }

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            error_log("❌ Supabase select error ($http_code): $response");
            return false;
        }

        return json_decode($response, true);
    }

    public function selectAll($table, $select = '*', $filters = [], $orderBy = null)
    {
        $allResults = [];
        $offset = 0;
        $limit = 1000;

        echo "📦 Récupération de toutes les données de la table '{$table}'...\n";

        while (true) {
            $options = [
                'limit' => $limit,
                'offset' => $offset
            ];

            if ($orderBy) {
                $options['order'] = $orderBy;
            }

            $results = $this->select($table, $select, $filters, $options);

            if ($results === false || empty($results)) {
                break;
            }

            $allResults = array_merge($allResults, $results);
            echo "   • Récupéré " . count($allResults) . " lignes...\r";

            if (count($results) < $limit) {
                break;
            }

            $offset += $limit;
        }

        echo "\n✅ Total récupéré : " . count($allResults) . " lignes de '{$table}'\n\n";
        return $allResults;
    }

    public function update($table, $data, $filters = [])
    {
        $url = "{$this->url}/{$table}?";

        foreach ($filters as $key => $value) {
            $url .= "{$key}={$value}&";
        }
        $url = rtrim($url, '&');

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 400) {
            error_log("❌ Supabase update error ($http_code): $response");
            return false;
        }

        return json_decode($response, true);
    }

    public function delete($table, $filters = [])
    {
        $url = "{$this->url}/{$table}?";

        foreach ($filters as $key => $value) {
            $url .= "{$key}={$value}&";
        }
        $url = rtrim($url, '&');

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code === 204 || $http_code === 200;
    }
    /**
     * Upsert en masse (batch)
     * @param string $table Nom de la table
     * @param array $rows Tableau de lignes à insérer [{col1: val1, ...}, ...]
     * @param string $onConflict Colonne de conflit (clé primaire)
     * @return bool|array
     */
    public function batchUpsert($table, $rows, $onConflict = null) {
        if (empty($rows)) {
            return true;
        }

        // ✅ Construction URL correcte
        $baseUrl = rtrim($this->url, '/');
        
        if (strpos($baseUrl, '/rest/v1') !== false) {
            $url = $baseUrl . "/{$table}";
        } else {
            $url = $baseUrl . "/rest/v1/{$table}";
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "apikey: {$this->key}",
                "Authorization: Bearer {$this->key}",
                "Content-Type: application/json",
                "Prefer: resolution=merge-duplicates"  // Upsert ici, pas dans l'URL
            ],
            CURLOPT_POSTFIELDS => json_encode($rows)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }
        
        error_log("Batch upsert error: HTTP {$httpCode} - {$response}");
        return false;
    }

}
