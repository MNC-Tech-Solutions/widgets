<?php

declare(strict_types=1);

class GhlRateLimitException extends RuntimeException
{
}

class GhlHttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $statusCode,
        private mixed $response = null
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponse(): mixed
    {
        return $this->response;
    }
}

class GhlClient
{
    private string $baseUrl = 'https://services.leadconnectorhq.com';
    private string $version = '2023-02-21';

    public function get(string $path, array $params, string $token): array
    {
        return $this->requestWithRetry('GET', $path, $params, null, $token);
    }

    public function post(string $path, array $params, array $body, string $token): array
    {
        return $this->requestWithRetry('POST', $path, $params, $body, $token);
    }

    public function getAbsolute(string $url, string $token): array
    {
        return $this->requestWithRetry('GET', $url, [], null, $token, true);
    }

    private function requestWithRetry(
        string $method,
        string $path,
        array $params,
        ?array $body,
        string $token,
        bool $absolute = false
    ): array {
        try {
            return $this->request($method, $path, $params, $body, $token, $absolute);
        } catch (GhlRateLimitException) {
            sleep(1);
            return $this->request($method, $path, $params, $body, $token, $absolute);
        }
    }

    private function request(
        string $method,
        string $path,
        array $params,
        ?array $body,
        string $token,
        bool $absolute
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required.');
        }

        $url = $absolute ? $path : $this->baseUrl . '/' . ltrim($path, '/');
        if ($params !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Version: ' . $this->version,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($method === 'POST') {
            $jsonBody = json_encode($body ?? [], JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody === false ? '{}' : $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('GHL request failed: ' . $curlError);
        }

        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('GHL returned invalid JSON.');
        }

        if ($status === 429) {
            throw new GhlRateLimitException('GHL rate limit reached.');
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : 'GHL request failed with HTTP ' . $status . '.';
            throw new GhlHttpException($message, $status, $decoded);
        }

        return is_array($decoded) ? $decoded : [];
    }
}

