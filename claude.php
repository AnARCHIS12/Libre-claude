<?php
/**
 * Libre Claude - Client API Claude (cURL only, Hostinger compatible)
 */

require_once dirname(__FILE__) . '/config.php';

class ClaudeClient {
    private $apiKeys;
    private $currentKeyIndex = 0;

    public function __construct($userApiKey = null) {
        if ($userApiKey && trim($userApiKey) !== '') {
            $this->apiKeys = [trim($userApiKey)];
        } else {
            $this->apiKeys = $this->getSharedApiKeys();
        }
    }

    private function getSharedApiKeys() {
        if (class_exists('Database')) {
            try {
                $db = Database::getInstance();
                $storedKeys = json_decode($db->getSetting('mistral_api_keys', '[]'), true);
                if (is_array($storedKeys)) {
                    $storedKeys = array_values(array_filter(array_map(function($item) {
                        if (is_array($item)) {
                            if (isset($item['active']) && !$item['active']) return '';
                            return trim($item['key'] ?? '');
                        }
                        return trim($item);
                    }, $storedKeys)));
                    if ($storedKeys) {
                        return $storedKeys;
                    }
                }
            } catch (Exception $e) {
                libreclaude_log("Config keys fallback: " . $e->getMessage(), 2);
            }
        }

        return DEFAULT_MISTRAL_API_KEYS;
    }

    public function chat($messages, $model = 'mistral-large-2512', $options = []) {
        $params = array_merge([
            'temperature' => 0.7,
            'max_tokens'  => 4096,
            'top_p'       => 1,
        ], $options);

        $params['model']    = $model;
        $params['messages'] = $messages;

        $maxTries = count($this->apiKeys) * 2;

        for ($i = 0; $i < $maxTries; $i++) {
            $apiKey = $this->apiKeys[$this->currentKeyIndex];

            try {
                $result = $this->doRequest($apiKey, $params);

                if (isset($result['choices'][0]['message']['content'])) {
                    return [
                        'success' => true,
                        'content' => $result['choices'][0]['message']['content'],
                        'model'   => $model,
                        'usage'   => $result['usage'] ?? [],
                    ];
                }
                throw new Exception('Réponse API invalide');

            } catch (Exception $e) {
                $msg = $e->getMessage();
                libreclaude_log("API key[$this->currentKeyIndex] error: $msg", 2);

                // Rotation si rate limit ou clé invalide
                if (strpos($msg, '429') !== false || strpos($msg, '401') !== false) {
                    $this->currentKeyIndex = ($this->currentKeyIndex + 1) % count($this->apiKeys);
                }
            }
        }

        return ['success' => false, 'error' => 'Toutes les clés API ont échoué. Vérifiez vos clés Claude.'];
    }

    public function transcribe($filePath, $fileName, $mimeType = 'audio/webm', $language = 'fr') {
        $maxTries = count($this->apiKeys) * 2;

        for ($i = 0; $i < $maxTries; $i++) {
            $apiKey = $this->apiKeys[$this->currentKeyIndex];

            try {
                $result = $this->doTranscriptionRequest($apiKey, $filePath, $fileName, $mimeType, $language);
                if (isset($result['text'])) {
                    return [
                        'success'  => true,
                        'text'     => trim($result['text']),
                        'language' => $result['language'] ?? null,
                        'model'    => $result['model'] ?? 'voxtral-mini-latest',
                        'usage'    => $result['usage'] ?? [],
                    ];
                }
                throw new Exception('Transcription invalide');
            } catch (Exception $e) {
                $msg = $e->getMessage();
                libreclaude_log("Transcription key[$this->currentKeyIndex] error: $msg", 2);

                if (strpos($msg, '429') !== false || strpos($msg, '401') !== false) {
                    $this->currentKeyIndex = ($this->currentKeyIndex + 1) % count($this->apiKeys);
                }
            }
        }

        return ['success' => false, 'error' => 'La dictée vocale a échoué. Vérifiez vos clés Claude.'];
    }

    private function doRequest($apiKey, $params) {
        $ch = curl_init(MISTRAL_API_ENDPOINT);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Libre Claude/1.0 (PHP cURL)',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("cURL: $error");
        if ($httpCode !== 200) {
            $decoded = json_decode($response, true);
            $errMsg  = $decoded['message'] ?? $decoded['error']['message'] ?? "HTTP $httpCode";
            throw new Exception($errMsg);
        }

        $decoded = json_decode($response, true);
        if (!$decoded) throw new Exception('JSON invalide');
        return $decoded;
    }

    private function doTranscriptionRequest($apiKey, $filePath, $fileName, $mimeType, $language) {
        $postFields = [
            'model' => 'voxtral-mini-latest',
            'file' => new CURLFile($filePath, $mimeType, $fileName),
        ];
        if ($language) {
            $postFields['language'] = $language;
        }

        $ch = curl_init(MISTRAL_TRANSCRIPTION_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Libre Claude/1.0 (PHP cURL)',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("cURL: $error");
        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode($response, true);
            $errMsg  = $decoded['message'] ?? $decoded['error']['message'] ?? "HTTP $httpCode";
            throw new Exception($errMsg);
        }

        $decoded = json_decode($response, true);
        if (!$decoded) throw new Exception('JSON invalide');
        return $decoded;
    }

    public function getModels() {
        return MISTRAL_MODELS;
    }
}

function getClaudeClient($userApiKey = null) {
    return new ClaudeClient($userApiKey);
}

function getMistralClient($userApiKey = null) {
    return getClaudeClient($userApiKey);
}
