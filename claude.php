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

    public function chatWithWebSearch($messages, $model = 'mistral-large-2512', $options = []) {
        $maxTries = count($this->apiKeys) * 2;
        $lastError = '';

        for ($i = 0; $i < $maxTries; $i++) {
            $apiKey = $this->apiKeys[$this->currentKeyIndex];

            try {
                $result = $this->doConversationRequest($apiKey, $messages, $model, $options);
                $parsed = $this->parseConversationResponse($result);
                if (trim($parsed['content']) !== '') {
                    return [
                        'success' => true,
                        'content' => trim($parsed['content']),
                        'model'   => $model,
                        'sources' => $parsed['sources'],
                        'usage'   => $result['usage'] ?? [],
                    ];
                }
                throw new Exception('Réponse web invalide');
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                libreclaude_log("Web search key[$this->currentKeyIndex] error: $lastError", 2);

                if (strpos($lastError, '429') !== false || strpos($lastError, '401') !== false) {
                    $this->currentKeyIndex = ($this->currentKeyIndex + 1) % count($this->apiKeys);
                }
            }
        }

        return ['success' => false, 'error' => 'Recherche web Mistral impossible. ' . $lastError];
    }

    public function speech($text, $voiceId = null, $format = 'mp3') {
        $cleanText = trim($text);
        if ($cleanText === '') {
            return ['success' => false, 'error' => 'Texte vide'];
        }

        $maxTries = count($this->apiKeys) * 2;
        $lastError = '';
        for ($i = 0; $i < $maxTries; $i++) {
            $apiKey = $this->apiKeys[$this->currentKeyIndex];

            try {
                $result = $this->doSpeechRequest($apiKey, $cleanText, $voiceId, $format);
                if (!empty($result['audio_data'])) {
                    return [
                        'success'    => true,
                        'audio_data' => $result['audio_data'],
                        'format'     => $result['format'] ?? $format,
                        'mime'       => $this->audioMimeType($result['format'] ?? $format),
                        'model'      => $result['model'] ?? MISTRAL_TTS_MODEL,
                    ];
                }
                throw new Exception('Synthèse vocale invalide');
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $lastError = $msg;
                libreclaude_log("Speech key[$this->currentKeyIndex] error: $msg", 2);

                if (strpos($msg, '429') !== false || strpos($msg, '401') !== false) {
                    $this->currentKeyIndex = ($this->currentKeyIndex + 1) % count($this->apiKeys);
                }
            }
        }

        $hint = 'La réponse vocale Mistral a échoué.';
        if (trim((string)MISTRAL_TTS_VOICE_ID) === '') {
            $hint .= ' Configurez MISTRAL_TTS_VOICE_ID ou laissez Libre Claude utiliser la voix locale du navigateur.';
        }
        if ($lastError !== '') {
            $hint .= ' Détail : ' . $lastError;
        }
        return ['success' => false, 'error' => $hint];
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
            $errMsg  = $this->normalizeApiError($decoded['message'] ?? $decoded['error']['message'] ?? $decoded['error'] ?? "HTTP $httpCode");
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
            $errMsg  = $this->normalizeApiError($decoded['message'] ?? $decoded['error']['message'] ?? $decoded['error'] ?? "HTTP $httpCode");
            throw new Exception($errMsg);
        }

        $decoded = json_decode($response, true);
        if (!$decoded) throw new Exception('JSON invalide');
        return $decoded;
    }

    private function doConversationRequest($apiKey, $messages, $model, $options) {
        $tool = trim((string)($options['web_search_tool'] ?? MISTRAL_WEB_SEARCH_TOOL));
        if ($tool === '') {
            $tool = 'web_search';
        }

        $payload = [
            'model'  => $model,
            'inputs' => $messages,
            'tools'  => [['type' => $tool]],
        ];

        $ch = curl_init(MISTRAL_CONVERSATIONS_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
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
        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode($response, true);
            $errMsg  = $this->normalizeApiError($decoded['message'] ?? $decoded['error']['message'] ?? $decoded['error'] ?? "HTTP $httpCode");
            throw new Exception($errMsg);
        }

        $decoded = json_decode($response, true);
        if (!$decoded) throw new Exception('JSON invalide');
        return $decoded;
    }

    private function parseConversationResponse($response) {
        $content = '';
        $sources = [];
        $this->walkConversationEntries($response, $content, $sources);

        $deduped = [];
        foreach ($sources as $source) {
            $url = trim($source['url'] ?? '');
            if ($url === '' || isset($deduped[$url])) continue;
            $deduped[$url] = [
                'title'  => trim($source['title'] ?? '') ?: parse_url($url, PHP_URL_HOST),
                'url'    => $url,
                'source' => trim($source['source'] ?? '') ?: parse_url($url, PHP_URL_HOST),
            ];
        }

        return [
            'content' => trim($content),
            'sources' => array_values($deduped),
        ];
    }

    private function walkConversationEntries($node, &$content, &$sources) {
        if (!is_array($node)) return;

        if (($node['type'] ?? '') === 'message.output' || (($node['role'] ?? '') === 'assistant' && isset($node['content']))) {
            $this->extractConversationContent($node['content'] ?? '', $content, $sources);
        } elseif (($node['type'] ?? '') === 'tool_reference' || isset($node['url'])) {
            $this->maybeAddConversationSource($node, $sources);
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->walkConversationEntries($value, $content, $sources);
            }
        }
    }

    private function extractConversationContent($contentNode, &$content, &$sources) {
        if (is_string($contentNode)) {
            $content .= "\n" . $contentNode;
            return;
        }

        if (!is_array($contentNode)) return;
        foreach ($contentNode as $chunk) {
            if (is_string($chunk)) {
                $content .= "\n" . $chunk;
                continue;
            }
            if (!is_array($chunk)) continue;

            $type = $chunk['type'] ?? '';
            if ($type === 'text' && isset($chunk['text'])) {
                $content .= "\n" . $chunk['text'];
            } elseif ($type === 'tool_reference' || isset($chunk['url'])) {
                $this->maybeAddConversationSource($chunk, $sources);
            }
        }
    }

    private function maybeAddConversationSource($chunk, &$sources) {
        $url = trim($chunk['url'] ?? '');
        if ($url === '') return;
        $sources[] = [
            'title'  => $chunk['title'] ?? '',
            'url'    => $url,
            'source' => $chunk['source'] ?? '',
        ];
    }

    private function doSpeechRequest($apiKey, $text, $voiceId, $format) {
        $format = in_array($format, ['mp3', 'wav', 'pcm', 'flac', 'opus'], true) ? $format : 'mp3';
        $payload = [
            'model'           => MISTRAL_TTS_MODEL,
            'input'           => $text,
            'response_format' => $format,
            'stream'          => false,
        ];

        $voiceId = trim((string)($voiceId ?: MISTRAL_TTS_VOICE_ID));
        if ($voiceId !== '') {
            $payload['voice_id'] = $voiceId;
        }

        $ch = curl_init(MISTRAL_SPEECH_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
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
        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode($response, true);
            $errMsg  = $this->normalizeApiError($decoded['message'] ?? $decoded['error']['message'] ?? $decoded['error'] ?? "HTTP $httpCode");
            throw new Exception($errMsg);
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded) && !empty($decoded['audio_data'])) {
            $decoded['format'] = $format;
            return $decoded;
        }

        if ($response !== '') {
            return [
                'audio_data' => base64_encode($response),
                'format'     => $format,
                'model'      => MISTRAL_TTS_MODEL,
            ];
        }

        throw new Exception('JSON invalide');
    }

    private function audioMimeType($format) {
        $map = [
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'pcm'  => 'audio/pcm',
            'flac' => 'audio/flac',
            'opus' => 'audio/ogg; codecs=opus',
        ];
        return $map[$format] ?? 'audio/mpeg';
    }

    private function normalizeApiError($error) {
        if (is_string($error)) {
            return $error;
        }
        if (is_array($error)) {
            return json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($error === null) {
            return 'Erreur API inconnue';
        }
        return (string)$error;
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
