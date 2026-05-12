<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

function normalizeItems(array $items): array
{
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string) ($item['name'] ?? $item['title'] ?? 'Untitled'));
        $normalized[] = [
            'name' => $name !== '' ? $name : 'Untitled',
        ];
    }

    return $normalized;
}

function listPayload(array $response, string $primaryKey): array
{
    $items = $response[$primaryKey] ?? $response['items'] ?? [];
    $items = is_array($items) ? normalizeItems($items) : [];

    return [
        'total' => (int) ($response['total'] ?? count($items)),
        'items' => $items,
    ];
}

$config = loadDashboardConfig();
$location = requireLocation($config);
$cache = dashboardCache($config);
$client = new GhlClient();
$locations = [];
$errors = [];
$cacheKey = 'marketing_' . $location['id'];

if (refreshRequested()) {
    $cache->invalidate($cacheKey);
}

$cached = $cache->get($cacheKey);
if (is_array($cached)) {
    jsonResponse([
        'locations' => [$cached],
        'errors' => [],
    ]);
}

try {
    $forms = $client->get('/forms/', [
        'locationId' => $location['id'],
        'limit' => 50,
    ], $location['accessToken']);
    usleep(150000);

    $surveys = $client->get('/surveys/', [
        'locationId' => $location['id'],
        'limit' => 50,
    ], $location['accessToken']);

    $payload = [
        'id' => $location['id'],
        'name' => $location['name'],
        'forms' => listPayload($forms, 'forms'),
        'surveys' => listPayload($surveys, 'surveys'),
    ];

    $cache->set($cacheKey, $payload, ttl($config, 'marketing', 1800));
    $locations[] = $payload;
} catch (Throwable $exception) {
    $errors[] = collectError($exception, $location, 'marketing');
}

jsonResponse([
    'locations' => $locations,
    'errors' => $errors,
]);

