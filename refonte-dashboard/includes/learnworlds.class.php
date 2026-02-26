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
        $this->base_url = getenv('LW_BASE_URL');
        $this->api_token = getenv('LW_API_TOKEN');
        $this->client_id = getenv('LW_CLIENT_ID');
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
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);

        curl_close($ch);

        // Logs debug
        logMessage("LW URL: " . $url);
        logMessage("LW HTTP: " . $http_code);
        logMessage("LW CURL_ERR: " . ($curl_err ?: "none"));
        logMessage("LW BODY: " . substr((string)$response, 0, 300));

        if ($response === false) {
            throw new Exception("cURL Error: " . $curl_err);
        }

        if ($http_code === 404) {
            return null;
        }

        if ($http_code < 200 || $http_code >= 300) {
            error_log("❌ LearnWorlds API error ($http_code): " . substr((string)$response, 0, 200));
            throw new Exception("API Error $http_code");
        }

        $json = json_decode((string)$response, true);

        // Optionnel : log si JSON invalide
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            logMessage("LW JSON ERROR: " . json_last_error_msg());
        }

        return $json;
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
        logMessage("LW PROGRESS KEYS: " . json_encode(array_keys($progress ?? [])));


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


    public function getUsersCourses($userId){
        return $this->makeRequest("/users/{$userId}/courses");
    }

    public function getCourses($page = 1, $perPage = 100) {
        return $this->makeRequest("/courses?page={$page}&per_page={$perPage}");
    }

    public function debugRequest($endpoint){
        return $this->makeRequest($endpoint);
    }

    /**
     * Récupérer les enrollments (inscriptions aux cours)
     * @param int $page Numéro de page
     * @param int $perPage Nombre de résultats par page
     * @return array|false
     */
    public function getEnrollments($page = 1, $perPage = 100) {
        $endpoint = "/enrollments?page={$page}&per_page={$perPage}";
        return $this->makeRequest($endpoint);
    }


    public function getAllEnrolledUserIds() {
        $enrolledIds = [];
        
        // 1. Récupérer TOUS les cours avec pagination
        $coursePage = 1;

        while (true) {
            $courses = $this->getCourses($coursePage, 100);
            
            if (empty($courses['data'])) {
                break;
            }
            
            logMessage("📚 Traitement de " . count($courses['data']) . " cours (page {$coursePage})");
            
            // 2. Pour chaque cours, récupérer les utilisateurs enrolled
            foreach ($courses['data'] as $course) {
                $courseId = $course['id'];
                $userPage = 1;
                
                while (true) {
                    try {
                        $enrolled = $this->getCourseEnrollments($courseId, $userPage, 100);
                        
                        if (empty($enrolled['data'])) {
                            break;
                        }
                        
                        foreach ($enrolled['data'] as $enrollment) {
                            $userId = $enrollment['user_id'] ?? $enrollment['id'] ?? null;
                            if ($userId) {
                                $enrolledIds[] = $userId;
                            }
                        }
                        
                        $userPage++;
                        if ($userPage > ($enrolled['meta']['totalPages'] ?? 1)) {
                            break;
                        }
                        
                        usleep(100000); // 0.1s entre pages
                        
                    } catch (Exception $e) {
                        logMessage("⚠️ Erreur cours {$courseId}: " . $e->getMessage(), 'WARNING');
                        break;
                    }
                }
            }
            
            $coursePage++;
            if ($coursePage > ($courses['meta']['totalPages'] ?? 1)) {
                break;
            }
            
            usleep(200000); // 0.2s entre pages de cours
        }
        
        $uniqueIds = array_unique($enrolledIds);
        logMessage("✅ " . count($uniqueIds) . " utilisateurs enrolled uniques trouvés");
        
        return $uniqueIds;
    }

    /**
     * Récupérer les enrollments d'un cours spécifique
     */
    public function getCourseEnrollments($courseId, $page = 1, $perPage = 100) {
        $endpoint = "/courses/{$courseId}/users?page={$page}&per_page={$perPage}";
        
        return $this->makeRequest($endpoint);
    }

}

