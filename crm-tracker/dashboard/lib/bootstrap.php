<?php

declare(strict_types=1);

require_once __DIR__ . '/FileCache.php';
require_once __DIR__ . '/GhlClient.php';

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function failJson(string $message, int $status = 500): never
{
    jsonResponse(['error' => $message], $status);
}

function loadDashboardConfig(): array
{
    $defaultPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.json';
    $configPath = getenv('GHL_DASHBOARD_CONFIG') ?: $defaultPath;

    if (!is_file($configPath)) {
        failJson('Missing config.json. Place it one directory above /dashboard or set GHL_DASHBOARD_CONFIG.');
    }

    $raw = file_get_contents($configPath);
    if ($raw === false) {
        failJson('Unable to read config.json.');
    }

    $config = json_decode($raw, true);
    if (!is_array($config)) {
        failJson('Invalid config.json. Expected a JSON object.');
    }

    $locations = $config['locations'] ?? null;
    if (!is_array($locations)) {
        failJson('Invalid config.json. Expected "locations" to be an array.');
    }

    foreach ($locations as $index => $location) {
        if (!is_array($location)) {
            failJson('Invalid location entry at index ' . $index . '.');
        }

        foreach (['id', 'name', 'accessToken'] as $field) {
            if (!isset($location[$field]) || !is_string($location[$field]) || trim($location[$field]) === '') {
                failJson('Invalid config.json. Location ' . $index . ' is missing "' . $field . '".');
            }
        }
    }

    return $config;
}

function dashboardCache(array $config): FileCache
{
    $cacheDir = $config['cache']['dir'] ?? (__DIR__ . '/../cache');
    if (!is_string($cacheDir) || trim($cacheDir) === '') {
        $cacheDir = __DIR__ . '/../cache';
    }

    if (!str_starts_with($cacheDir, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $cacheDir)) {
        $cacheDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $cacheDir;
    }

    return new FileCache($cacheDir, 300);
}

function ttl(array $config, string $name, int $default): int
{
    $value = $config['cache']['ttls'][$name] ?? $default;
    return is_int($value) && $value > 0 ? $value : $default;
}

function refreshRequested(): bool
{
    return isset($_GET['refresh']) && $_GET['refresh'] === '1';
}

function publicLocations(array $config): array
{
    return array_map(
        fn (array $location): array => [
            'id' => $location['id'],
            'name' => $location['name'],
        ],
        $config['locations']
    );
}

function requireLocation(array $config): array
{
    $locationId = isset($_GET['locationId']) ? trim((string) $_GET['locationId']) : '';
    if ($locationId === '') {
        failJson('Missing required locationId query parameter.', 400);
    }

    foreach ($config['locations'] as $location) {
        if ($location['id'] === $locationId) {
            return $location;
        }
    }

    failJson('Invalid locationId. The selected location does not exist in config.json.', 400);
}

function normalizeUser(array $user): array
{
    $firstName = trim((string) ($user['firstName'] ?? ''));
    $lastName = trim((string) ($user['lastName'] ?? ''));
    $fullName = trim((string) ($user['name'] ?? ($firstName . ' ' . $lastName)));

    return [
        'id' => (string) ($user['id'] ?? $user['_id'] ?? ''),
        'name' => $fullName !== '' ? $fullName : 'Unassigned',
    ];
}

function fetchUsersForLocation(GhlClient $client, FileCache $cache, array $config, array $location): array
{
    $cacheKey = 'users_' . $location['id'];

    if (refreshRequested()) {
        $cache->invalidate($cacheKey);
    }

    $cached = $cache->get($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $response = $client->get('/users/', ['locationId' => $location['id']], $location['accessToken']);
    $rawUsers = $response['users'] ?? [];
    $users = [];

    if (is_array($rawUsers)) {
        foreach ($rawUsers as $rawUser) {
            if (!is_array($rawUser)) {
                continue;
            }

            $user = normalizeUser($rawUser);
            if ($user['id'] !== '') {
                $users[] = $user;
            }
        }
    }

    $payload = [
        'locationId' => $location['id'],
        'users' => $users,
    ];

    $cache->set($cacheKey, $payload, ttl($config, 'users', 1800));
    return $payload;
}

function collectError(Throwable $exception, array $location, string $tab): array
{
    $message = $exception instanceof GhlHttpException
        ? $exception->getMessage() . ' (HTTP ' . $exception->getStatusCode() . ')'
        : $exception->getMessage();

    return [
        'locationId' => $location['id'] ?? null,
        'locationName' => $location['name'] ?? null,
        'tab' => $tab,
        'message' => $message,
    ];
}
