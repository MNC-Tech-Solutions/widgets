<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

function normalizeAccounts(array $response): array
{
    $accounts = $response['results']['accounts'] ?? $response['accounts'] ?? [];
    if (!is_array($accounts)) {
        return [];
    }

    $normalized = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }

        $profileId = (string) ($account['profileId'] ?? $account['id'] ?? '');
        if ($profileId === '') {
            continue;
        }

        $normalized[] = [
            'profileId' => $profileId,
            'platform' => (string) ($account['platform'] ?? 'unknown'),
            'name' => (string) ($account['name'] ?? $account['displayName'] ?? 'Untitled account'),
        ];
    }

    return $normalized;
}

function queryDate(string $name, string $default): string
{
    $value = isset($_GET[$name]) ? trim((string) $_GET[$name]) : $default;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        failJson('Invalid ' . $name . '. Expected YYYY-MM-DD.', 400);
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $value) {
        failJson('Invalid ' . $name . '. Expected a real date in YYYY-MM-DD format.', 400);
    }

    return $value;
}

$config = loadDashboardConfig();
$location = requireLocation($config);
$cache = dashboardCache($config);
$client = new GhlClient();
$locations = [];
$errors = [];
$startDate = queryDate('startDate', '2020-01-01');
$endDate = queryDate('endDate', gmdate('Y-m-d'));

if ($startDate > $endDate) {
    failJson('Invalid date range. startDate must be before or equal to endDate.', 400);
}

$cacheKey = 'social_' . $location['id'] . '_' . $startDate . '_' . $endDate;

if (refreshRequested()) {
    $cache->invalidate($cacheKey);
}

$cached = $cache->get($cacheKey);
if (is_array($cached)) {
    if (array_key_exists('results', $cached)) {
        jsonResponse([
            'locations' => [$cached],
            'errors' => [],
        ]);
    }

    $cache->invalidate($cacheKey);
}

try {
    $accountsResponse = $client->get('/social-media-posting/' . rawurlencode($location['id']) . '/accounts', [], $location['accessToken']);
    $accounts = normalizeAccounts($accountsResponse);

    $payload = [
        'id' => $location['id'],
        'name' => $location['name'],
        'accounts' => $accounts,
        'dateRange' => [
            'startDate' => $startDate,
            'endDate' => $endDate,
        ],
        'results' => null,
        'message' => null,
        'traceId' => null,
    ];

    if ($accounts !== []) {
        usleep(150000);
        $profileIds = array_map(fn (array $account): string => $account['profileId'], $accounts);
        $analytics = $client->post('/social-media-posting/statistics', [
            'locationId' => $location['id'],
        ], [
            'profileIds' => $profileIds,
            'currentRange' => [
                'startDate' => $startDate . 'T00:00:00.000Z',
                'endDate' => $endDate . 'T23:59:59.000Z',
            ],
        ], $location['accessToken']);

        $payload['results'] = is_array($analytics['results'] ?? null) ? $analytics['results'] : null;
        $payload['message'] = isset($analytics['message']) ? (string) $analytics['message'] : null;
        $payload['traceId'] = isset($analytics['traceId']) ? (string) $analytics['traceId'] : null;
    }

    $cache->set($cacheKey, $payload, ttl($config, 'social', 300));
    $locations[] = $payload;
} catch (Throwable $exception) {
    $errors[] = collectError($exception, $location, 'social');
}

jsonResponse([
    'locations' => $locations,
    'errors' => $errors,
]);
