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

$plan = require APP_ROOT . '/resources/data/personnel-organization-2026-07-14.php';
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

foreach ($plan['manager_emails'] as $managerEmail) {
    if (!isset($profiles[$managerEmail])) {
        $profiles[$managerEmail] = [
            'email' => $managerEmail,
            'name' => $managerEmail,
            'department' => 'Before',
            'location' => 'antalya',
            'workforce_roles' => [],
            'password_hash' => 'preserve-' . $managerEmail,
        ];
    }
}

file_put_contents($profilePath, json_encode(['version' => 3, 'profiles' => $profiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($accessPath, json_encode([
    'version' => 15,
    'departments' => [],
    'user_permissions' => [],
    'department_policies' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$store = new StateStore(null, ['driver' => 'file', 'lock_timeout' => 2]);
$sync = new PersonnelOrganizationSync($store, $profilePath, $accessPath);
$preview = $sync->synchronize($plan, false);
organizationAssert($preview['assignments_requested'] === 73, 'The workbook plan must contain 73 personnel assignments.');
organizationAssert($preview['unmatched'] === 0, 'The preview must not contain unmatched personnel.');
$beforeCount = count($profiles);
$result = $sync->synchronize($plan, true);
organizationAssert($result['mode'] === 'applied', 'The sync must report applied mode.');
organizationAssert($result['profile_count_after'] === $beforeCount, 'The sync must not create or delete personnel.');

$storedProfiles = json_decode((string) file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR)['profiles'];
$storedAccess = json_decode((string) file_get_contents($accessPath), true, 512, JSON_THROW_ON_ERROR);
organizationAssert($storedProfiles['a.kulali@takii.com.tr']['department'] === 'Araştırma - Lab / Özgecan', 'Aylin department assignment failed.');
organizationAssert($storedProfiles['f.karan@takii.com.tr']['department'] === 'Operasyon / Erdi', 'Furkan department assignment failed.');
organizationAssert($storedProfiles['no-email-saniye-surer']['department'] === 'RD BC / Yeşim', 'Saniye department assignment failed.');
organizationAssert($storedProfiles['no-email-zubeyde-bagis']['department'] === 'RD Long Term', 'RD Long Term must be preserved.');
organizationAssert($storedProfiles['a.kulali@takii.com.tr']['password_hash'] === 'preserve-a.kulali@takii.com.tr', 'Unrelated profile fields must be preserved.');
organizationAssert(in_array('manager', $storedProfiles['d.kaya@takii.com.tr']['workforce_roles'], true), 'Managers must receive the manager workforce role.');
organizationAssert($storedAccess['departments']['Operasyon / Erdi']['parent'] === 'Operasyon', 'Department hierarchy failed.');
organizationAssert($storedAccess['department_policies']['Operasyon / Erdi']['manager_1_email'] === 'e.oz@takii.com.tr', 'Manager approval policy failed.');
organizationAssert(in_array('personnel.read', $storedAccess['user_permissions']['e.oz@takii.com.tr'], true), 'Manager personnel visibility permission failed.');

$idempotent = $sync->synchronize($plan, false);
organizationAssert($idempotent['assignments_changed'] === 0, 'A second run must be idempotent for assignments.');
organizationAssert($idempotent['policies_changed'] === 0, 'A second run must be idempotent for policies.');

@unlink($profilePath);
@unlink($accessPath);
@unlink($profilePath . '.lock');
@unlink($accessPath . '.lock');
@rmdir($directory);

echo "Personnel organization sync test passed.\n";
