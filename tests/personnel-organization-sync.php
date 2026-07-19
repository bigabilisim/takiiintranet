<?php

declare(strict_types=1);

use App\Core\PersonnelOrganizationSync;
use App\Core\StateStore;

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

function organizationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$plan = [
    'assignments' => [
        'manager.one@example.test' => [
            'expected_name' => 'Manager One',
            'department' => 'Operations / Field',
            'location' => 'antalya',
        ],
        'employee.one@example.test' => [
            'expected_name' => 'Employee One',
            'department' => 'Operations / Field',
            'location' => 'antalya',
        ],
        'no-email-worker-one' => [
            'expected_name' => 'Worker One',
            'department' => 'Production / Line One',
            'location' => 'bursa',
        ],
    ],
    'departments' => [
        'Operations' => '',
        'Operations / Field' => 'Operations',
        'Production' => '',
        'Production / Line One' => 'Production',
    ],
    'policies' => [
        'Operations / Field' => 'manager.one@example.test',
    ],
    'manager_emails' => ['manager.one@example.test'],
    'hr_email' => 'hr.approver@example.test',
];

$directory = sys_get_temp_dir() . '/takii-organization-' . bin2hex(random_bytes(5));
mkdir($directory, 0775, true);
$profilePath = $directory . '/user-profiles.json';
$accessPath = $directory . '/access-control.json';
$profiles = [];

foreach ($plan['assignments'] as $profileKey => $assignment) {
    $profiles[$profileKey] = [
        'email' => filter_var($profileKey, FILTER_VALIDATE_EMAIL) ? $profileKey : '',
        'name' => $assignment['expected_name'],
        'department' => 'Before',
        'location' => '',
        'workforce_roles' => [],
        'password_hash' => 'preserve-' . $profileKey,
    ];
}

file_put_contents($profilePath, json_encode(['version' => 4, 'profiles' => $profiles], JSON_PRETTY_PRINT));
file_put_contents($accessPath, json_encode([
    'version' => 15,
    'departments' => [],
    'user_permissions' => [],
    'department_policies' => [],
], JSON_PRETTY_PRINT));

$store = new StateStore(null, ['driver' => 'file', 'lock_timeout' => 2]);
$sync = new PersonnelOrganizationSync($store, $profilePath, $accessPath);
$preview = $sync->synchronize($plan, false);
organizationAssert($preview['assignments_requested'] === 3, 'The fixture must contain three personnel assignments.');
organizationAssert($preview['unmatched'] === 0, 'The preview must not contain unmatched personnel.');
$beforeCount = count($profiles);
$result = $sync->synchronize($plan, true);
organizationAssert($result['mode'] === 'applied', 'The sync must report applied mode.');
organizationAssert($result['profile_count_after'] === $beforeCount, 'The sync must not create or delete personnel.');

$storedProfiles = json_decode((string) file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR)['profiles'];
$storedAccess = json_decode((string) file_get_contents($accessPath), true, 512, JSON_THROW_ON_ERROR);
organizationAssert($storedProfiles['employee.one@example.test']['department'] === 'Operations / Field', 'Department assignment failed.');
organizationAssert($storedProfiles['no-email-worker-one']['location'] === 'bursa', 'Location assignment failed.');
organizationAssert($storedProfiles['employee.one@example.test']['password_hash'] === 'preserve-employee.one@example.test', 'Unrelated profile fields must be preserved.');
organizationAssert(in_array('manager', $storedProfiles['manager.one@example.test']['workforce_roles'], true), 'Managers must receive the manager workforce role.');
organizationAssert($storedAccess['departments']['Operations / Field']['parent'] === 'Operations', 'Department hierarchy failed.');
organizationAssert($storedAccess['department_policies']['Operations / Field']['manager_1_email'] === 'manager.one@example.test', 'Manager approval policy failed.');
organizationAssert(in_array('personnel.read', $storedAccess['user_permissions']['manager.one@example.test'], true), 'Manager personnel visibility permission failed.');

$idempotent = $sync->synchronize($plan, false);
organizationAssert($idempotent['assignments_changed'] === 0, 'A second run must be idempotent for assignments.');
organizationAssert($idempotent['policies_changed'] === 0, 'A second run must be idempotent for policies.');

@unlink($profilePath);
@unlink($accessPath);
@unlink($profilePath . '.lock');
@unlink($accessPath . '.lock');
@rmdir($directory);

echo "Personnel organization sync test passed.\n";
