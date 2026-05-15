<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

$config   = loadDashboardConfig();
$location = requireLocation($config);
$cache    = dashboardCache($config);
$client   = new GhlClient();
$cacheKey = 'avanser_' . $location['id'];

if (refreshRequested()) {
    $cache->invalidate($cacheKey);
}

$cached = $cache->get($cacheKey);
if (is_array($cached)) {
    jsonResponse(['locations' => [$cached], 'errors' => []]);
}

try {
    $workflowsRes = $client->get('/workflows/', ['locationId' => $location['id']], $location['accessToken']);
    usleep(150000);
    $numbersRes = $client->get('/phone-system/numbers/location/' . $location['id'], [], $location['accessToken']);

    $allWorkflows    = is_array($workflowsRes['workflows'] ?? null) ? $workflowsRes['workflows'] : [];
    $avanserWorkflows = array_values(array_filter(
        $allWorkflows,
        fn ($w) => is_array($w) && stripos((string) ($w['name'] ?? ''), 'avanser') !== false
    ));

    $allNumbers = is_array($numbersRes['numbers'] ?? null) ? $numbersRes['numbers'] : [];

    $payload = [
        'id'   => $location['id'],
        'name' => $location['name'],
        'workflows' => array_map(fn ($w) => [
            'name'      => (string) ($w['name'] ?? ''),
            'status'    => (string) ($w['status'] ?? ''),
            'version'   => (int) ($w['version'] ?? 1),
            'updatedAt' => (string) ($w['updatedAt'] ?? ''),
        ], $avanserWorkflows),
        'numbers' => [
            'accountStatus' => (string) ($numbersRes['status'] ?? ''),
            'items' => array_map(fn ($n) => [
                'friendlyName'       => (string) ($n['friendlyName'] ?? ''),
                'phoneNumber'        => (string) ($n['phoneNumber'] ?? ''),
                'countryCode'        => (string) ($n['countryCode'] ?? ''),
                'type'               => (string) ($n['type'] ?? ''),
                'isDefaultNumber'    => (bool) ($n['isDefaultNumber'] ?? false),
                'capabilities'       => [
                    'voice' => (bool) ($n['capabilities']['voice'] ?? false),
                    'sms'   => (bool) ($n['capabilities']['sms']   ?? false),
                    'mms'   => (bool) ($n['capabilities']['mms']   ?? false),
                    'fax'   => (bool) ($n['capabilities']['fax']   ?? false),
                ],
                'inboundCallService' => isset($n['inboundCallService']['type'])
                    ? ['type' => (string) $n['inboundCallService']['type']]
                    : null,
                'dateAdded' => (string) ($n['dateAdded'] ?? ''),
            ], $allNumbers),
        ],
    ];

    $cache->set($cacheKey, $payload, ttl($config, 'avanser', 1800));
    jsonResponse(['locations' => [$payload], 'errors' => []]);
} catch (Throwable $exception) {
    jsonResponse([
        'locations' => [],
        'errors'    => [collectError($exception, $location, 'avanser')],
    ]);
}
