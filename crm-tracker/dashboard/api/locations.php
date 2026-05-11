<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

$config = loadDashboardConfig();
$locations = publicLocations($config);

jsonResponse([
    'locations' => $locations,
    'default' => $locations[0]['id'] ?? null,
]);

