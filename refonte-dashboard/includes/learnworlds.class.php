<?php
require_once __DIR__ . '/../config/config.php';

class LearnWorlds
{
    private $base_url;
    private $api_token;
    private $client_id;
    private $request_count = 0;
    private $rate_limit = 100;
    private $rate_window_start;

    public function __construct()
    {
        $this->base_url = LW_BASE_URL;
        $this->api_token = LW_API_TOKEN;
        $this->client_id = LW_CLIENT_ID;
        $this->rate_window_start = time();
    }

    private function getHeaders()
    {
        return [
            'Authorization: Bearer ' . $this->api_token,
            'Lw-Client: ' . $this->client_id,
            'Accept: application/json',
            'Content-Type: application/json'
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

    private function checkRateLimit()
    {
        $now = time();

        if ($now - $this->rate_window_start >= 60) {
            $this->request_count = 0;
            $this->rate_window_start = $now;
        }

        if ($this->request_count >= $this->rate_limit) {
            $sleep_time = 60 - ($now - $this->rate_window_start);
            if ($sleep_time > 0) {
                echo "⏳ Rate limit atteint, pause de {$sleep_time}s...\n";
                sleep($sleep_time);
                $this->request_count = 0;
                $this->rate_window_start = time();
            }
        }

        $this->request_count++;
    }

    private function makeRequest($endpoint, $method = 'GET', $data = null)
    {
        $this->checkRateLimit();

        $url = $this->base_url . $endpoint;

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 404) {
            return null;
        }

        if ($http_code !== 200) {
            error_log("❌ LearnWorlds API error ($http_code): " . substr($response, 0, 200));
            throw new Exception("API Error $http_code");
        }

        return json_decode($response, true);
    }

    public function getUsers($page = 1, $perPage = 100)
    {
        return $this->makeRequest("/users?page={$page}&per_page={$perPage}");
    }

    /**
     * ✨ NOUVELLE MÉTHODE : Vérifier si un utilisateur est inscrit à des cours
     * @param string $userId ID de l'utilisateur
     * @return bool True si l'utilisateur a au moins un cours
     */
    public function isUserEnrolled($userId)
    {
        try {
            $courses = $this->makeRequest("/users/{$userId}/courses");

            // Vérifier s'il y a des cours
            if ($courses && isset($courses['data']) && is_array($courses['data'])) {
                return count($courses['data']) > 0;
            }

            return false;
        } catch (Exception $e) {
            // Si erreur 404 ou autre, considérer que l'utilisateur n'a pas de cours
            return false;
        }
    }

    /**
     * ✨ NOUVELLE MÉTHODE : Récupérer les cours d'un utilisateur
     * @param string $userId ID de l'utilisateur
     * @return array Liste des cours ou tableau vide
     */
    public function getUserCourses($userId)
    {
        try {
            $courses = $this->makeRequest("/users/{$userId}/courses");

            if ($courses && isset($courses['data'])) {
                return $courses['data'];
            }

            return [];
        } catch (Exception $e) {
            return [];
        }
    }

    public function getUserProgress($userId)
    {
        return $this->makeRequest("/users/{$userId}/progress");
    }

    public function getUserCourseProgress($userId, $courseId)
    {
        return $this->makeRequest("/users/{$userId}/courses/{$courseId}/progress");
    }

    public function getUserTimeByLevel($userId)
    {
        $progress = $this->getUserProgress($userId);

        if (!$progress || !isset($progress['data'])) {
            return array_fill_keys(NIVEAUX, 0);
        }

        $result = array_fill_keys(NIVEAUX, 0);

        foreach ($progress['data'] as $course) {
            $courseId = strtolower($course['course_id'] ?? '');
            $time = $course['time_on_course'] ?? 0;

            if (isset(COURSE_MAPPING[$courseId])) {
                $niveau = COURSE_MAPPING[$courseId];
                $result[$niveau] += $time;
                continue;
            }

            foreach (NIVEAUX as $niveau) {
                if (strpos($courseId, $niveau) !== false) {
                    $result[$niveau] += $time;
                    break;
                }
            }

            if (strpos($courseId, 'premiere') !== false) {
                $result['1ere'] += $time;
            } elseif (strpos($courseId, 'seconde') !== false) {
                $result['2nde'] += $time;
            } elseif (strpos($courseId, 'terminale') !== false) {
                if (strpos($courseId, 'pc') !== false) {
                    $result['term-pc'] += $time;
                } else {
                    $result['term'] += $time;
                }
            }
        }

        return $result;
    }

    public function getUserProgressionByLevel($userId)
    {
        $result = array_fill_keys(NIVEAUX, 0);

        foreach (NIVEAUX as $niveau) {
            $mainCourseId = null;
            foreach (COURSE_MAPPING as $courseId => $mappedNiveau) {
                if ($mappedNiveau === $niveau && strpos($courseId, 'maths-') === 0) {
                    $mainCourseId = $courseId;
                    break;
                }
            }

            if (!$mainCourseId) continue;

            try {
                $progress = $this->getUserCourseProgress($userId, $mainCourseId);

                if ($progress && isset($progress['progress_rate'])) {
                    $result[$niveau] = round($progress['progress_rate']);
                } elseif ($progress && isset($progress['completed_units']) && isset($progress['total_units'])) {
                    if ($progress['total_units'] > 0) {
                        $result[$niveau] = round(($progress['completed_units'] / $progress['total_units']) * 100);
                    }
                }
            } catch (Exception $e) {
                // Cours non inscrit
            }
        }

        return $result;
    }
}
