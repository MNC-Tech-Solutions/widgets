<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

$config = loadDashboardConfig();
$location = requireLocation($config);
$cache = dashboardCache($config);
$client = new GhlClient();
$locations = [];
$errors = [];

try {
    $usersPayload = fetchUsersForLocation($client, $cache, $config, $location);
    $locations[] = [
        'id' => $location['id'],
        'name' => $location['name'],
        'users' => $usersPayload['users'],
    ];
} catch (Throwable $exception) {
    $errors[] = collectError($exception, $location, 'users');
}

jsonResponse([
    'locations' => $locations,
    'errors' => $errors,
]);

