<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';

$pdo = db();
$definitions = planDefinitions();
$moduleAvailability = [
    'accounting' => systemModuleEnabled($pdo, 'accounting'),
    'dentistry' => systemModuleEnabled($pdo, 'dentistry'),
];
$plans = [];
foreach ($definitions as $key => $plan) {
    $module = (string)($plan['module'] ?? 'accounting');
    if (empty($moduleAvailability[$module])) continue;
    $metrics = $module === 'dentistry'
        ? ['doctors', 'patients', 'appointments_month']
        : ['managers', 'participants', 'projects', 'tasks_month', 'clients'];
    $limits = [];
    foreach ($metrics as $metric) $limits[$metric] = $plan[$metric] ?? null;
    $features = [];
    foreach ($plan as $feature => $enabled) {
        if (is_bool($enabled)) $features[$feature] = $enabled;
    }
    $plans[] = [
        'key' => $key,
        'module' => $module,
        'name' => $plan['name'],
        'price' => $plan['price'],
        'amount' => (float)($plan['amount'] ?? 0),
        'limits' => $limits,
        'features' => $features,
    ];
}

$config = appConfig();
jsonResponse(true, 'Тарифи завантажено.', [
    'plans' => $plans,
    'modules' => $moduleAvailability,
    'billing_enabled' => filter_var($config['billing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
]);
