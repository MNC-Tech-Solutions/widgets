<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

function userMap(array $users): array
{
    $map = [];
    foreach ($users as $user) {
        if (!is_array($user) || !isset($user['id'])) {
            continue;
        }

        $map[(string) $user['id']] = [
            'id' => (string) $user['id'],
            'name' => (string) ($user['name'] ?? 'Unassigned'),
            'appointments' => 0,
            'bookings' => 0,
        ];
    }

    return $map;
}

function sortedTotals(array $rows, string $field): array
{
    $items = array_values(array_map(
        fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'total' => $row[$field],
        ],
        $rows
    ));

    usort($items, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));
    return $items;
}

function findBookingStageId(array $pipelines): ?string
{
    $pipelineList = $pipelines['pipelines'] ?? [];
    if (!is_array($pipelineList)) {
        return null;
    }

    foreach ($pipelineList as $pipeline) {
        if (!is_array($pipeline)) {
            continue;
        }

        $stages = $pipeline['stages'] ?? [];
        if (!is_array($stages)) {
            continue;
        }

        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $name = strtolower(trim((string) ($stage['name'] ?? '')));
            if ($name !== '' && str_contains($name, 'booking')) {
                return (string) ($stage['id'] ?? $stage['_id'] ?? '');
            }
        }
    }

    return null;
}

function opportunitiesFromResponse(array $response): array
{
    $items = $response['opportunities'] ?? $response['items'] ?? [];
    return is_array($items) ? $items : [];
}

$config = loadDashboardConfig();
$location = requireLocation($config);
$cache = dashboardCache($config);
$client = new GhlClient();
$locations = [];
$errors = [];
$cacheKey = 'appointments_' . $location['id'];

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
    $usersPayload = fetchUsersForLocation($client, $cache, $config, $location);
    $users = $usersPayload['users'];
    $rows = userMap($users);
    $appointmentTotal = 0;
    $bookingTotal = 0;
    $endTime = time() * 1000;

    foreach ($users as $user) {
        $eventsResponse = $client->get('/calendars/events', [
            'locationId' => $location['id'],
            'userId' => $user['id'],
            'startTime' => 1577808000000,
            'endTime' => $endTime,
        ], $location['accessToken']);

        $events = $eventsResponse['events'] ?? $eventsResponse['calendarEvents'] ?? [];
        $count = is_array($events) ? count($events) : 0;
        $rows[$user['id']]['appointments'] = $count;
        $appointmentTotal += $count;
        usleep(150000);
    }

    $pipelines = $client->get('/opportunities/pipelines', [
        'locationId' => $location['id'],
    ], $location['accessToken']);
    $stageId = findBookingStageId($pipelines);
    $stageFound = $stageId !== null && $stageId !== '';

    if ($stageFound) {
        $response = $client->get('/opportunities/search', [
            'location_id' => $location['id'],
            'pipeline_stage_id' => $stageId,
        ], $location['accessToken']);

        while (true) {
            foreach (opportunitiesFromResponse($response) as $opportunity) {
                if (!is_array($opportunity)) {
                    continue;
                }

                $assignedTo = (string) ($opportunity['assignedTo'] ?? '');
                if ($assignedTo === '') {
                    $assignedTo = 'unassigned';
                }

                if (!isset($rows[$assignedTo])) {
                    $rows[$assignedTo] = [
                        'id' => $assignedTo,
                        'name' => $assignedTo === 'unassigned' ? 'Unassigned' : 'Unknown User',
                        'appointments' => 0,
                        'bookings' => 0,
                    ];
                }

                $rows[$assignedTo]['bookings']++;
                $bookingTotal++;
            }

            $nextPageUrl = $response['meta']['nextPageUrl'] ?? null;
            if (!is_string($nextPageUrl) || trim($nextPageUrl) === '') {
                break;
            }

            usleep(150000);
            $response = $client->getAbsolute($nextPageUrl, $location['accessToken']);
        }
    }

    $payload = [
        'id' => $location['id'],
        'name' => $location['name'],
        'appointments' => [
            'total' => $appointmentTotal,
            'byUser' => sortedTotals($rows, 'appointments'),
        ],
        'bookings' => [
            'total' => $bookingTotal,
            'byUser' => sortedTotals($rows, 'bookings'),
            'stageFound' => $stageFound,
        ],
    ];

    $cache->set($cacheKey, $payload, ttl($config, 'appointments', 300));
    $locations[] = $payload;
} catch (Throwable $exception) {
    $errors[] = collectError($exception, $location, 'appointments');
}

jsonResponse([
    'locations' => $locations,
    'errors' => $errors,
]);

