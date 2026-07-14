<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class PersonnelOrganizationSync
{
    private const MANAGER_PERMISSIONS = [
        'module.leave.access',
        'leave.request.approve.department',
        'module.personnel.access',
        'personnel.read',
        'personnel.write',
    ];

    public function __construct(
        private readonly StateStore $stateStore,
        private readonly ?string $profilePath = null,
        private readonly ?string $accessPath = null,
    ) {
    }

    public function synchronize(array $plan, bool $apply = false): array
    {
        if (!$apply) {
            $profiles = $this->stateStore->read('user_profiles', $this->profilesPath(), [
                'version' => 3,
                'profiles' => [],
            ]);
            $access = $this->stateStore->read('access_control', $this->accessControlPath(), [
                'version' => 15,
                'departments' => [],
                'user_permissions' => [],
                'department_policies' => [],
            ]);

            return $this->transform($profiles, $access, $plan, false);
        }

        $report = [];
        $this->stateStore->transaction([
            [
                'key' => 'user_profiles',
                'path' => $this->profilesPath(),
                'default' => ['version' => 4, 'profiles' => []],
            ],
            [
                'key' => 'access_control',
                'path' => $this->accessControlPath(),
                'default' => [
                    'version' => 15,
                    'departments' => [],
                    'user_permissions' => [],
                    'department_policies' => [],
                ],
            ],
        ], function () use ($plan, &$report): void {
            $profiles = $this->stateStore->read('user_profiles', $this->profilesPath());
            $access = $this->stateStore->read('access_control', $this->accessControlPath());
            $report = $this->transform($profiles, $access, $plan, true);
            $this->stateStore->write('user_profiles', $this->profilesPath(), $profiles);
            $this->stateStore->write('access_control', $this->accessControlPath(), $access);
        });

        return $report;
    }

    private function transform(array &$profileDocument, array &$accessDocument, array $plan, bool $applied): array
    {
        $profiles = is_array($profileDocument['profiles'] ?? null) ? $profileDocument['profiles'] : [];
        $assignments = is_array($plan['assignments'] ?? null) ? $plan['assignments'] : [];
        $departments = is_array($plan['departments'] ?? null) ? $plan['departments'] : [];
        $policies = is_array($plan['policies'] ?? null) ? $plan['policies'] : [];
        $managerEmails = array_values(array_unique(array_map('strval', $plan['manager_emails'] ?? [])));
        $hrEmail = strtolower(trim((string) ($plan['hr_email'] ?? '')));
        $missingProfiles = [];
        $nameMismatches = [];
        $invalidLocations = [];

        foreach ($assignments as $profileKey => $assignment) {
            if (!isset($profiles[$profileKey]) || !is_array($profiles[$profileKey])) {
                $missingProfiles[] = (string) $profileKey;
                continue;
            }

            $expectedName = trim((string) ($assignment['expected_name'] ?? ''));

            if ($expectedName !== '' && $this->normalizedName((string) ($profiles[$profileKey]['name'] ?? '')) !== $this->normalizedName($expectedName)) {
                $nameMismatches[] = [
                    'profile_key' => (string) $profileKey,
                    'expected' => $expectedName,
                    'actual' => (string) ($profiles[$profileKey]['name'] ?? ''),
                ];
            }

            if (!in_array((string) ($assignment['location'] ?? ''), [LocationScope::ANTALYA, LocationScope::BURSA], true)) {
                $invalidLocations[] = (string) $profileKey;
            }
        }

        $missingManagers = array_values(array_filter(
            $managerEmails,
            fn (string $email): bool => !$this->profileWithEmailExists($profiles, $email)
        ));

        if ($missingProfiles !== [] || $nameMismatches !== [] || $invalidLocations !== [] || $missingManagers !== []) {
            throw new RuntimeException(json_encode([
                'missing_profiles' => $missingProfiles,
                'name_mismatches' => $nameMismatches,
                'invalid_locations' => $invalidLocations,
                'missing_managers' => $missingManagers,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $now = date('Y-m-d H:i');
        $assignmentChanges = 0;

        foreach ($assignments as $profileKey => $assignment) {
            $department = trim((string) ($assignment['department'] ?? ''));
            $location = (string) ($assignment['location'] ?? '');
            $changed = (string) ($profiles[$profileKey]['department'] ?? '') !== $department
                || (string) ($profiles[$profileKey]['location'] ?? '') !== $location;

            if (!$changed) {
                continue;
            }

            $profiles[$profileKey]['department'] = $department;
            $profiles[$profileKey]['location'] = $location;
            $profiles[$profileKey]['updated_at'] = $now;
            $assignmentChanges++;
        }

        $managerProfileChanges = 0;

        foreach ($managerEmails as $managerEmail) {
            $profileKey = $this->profileKeyForEmail($profiles, $managerEmail);

            if ($profileKey === null) {
                continue;
            }

            $roles = is_array($profiles[$profileKey]['workforce_roles'] ?? null)
                ? array_values(array_unique(array_map('strval', $profiles[$profileKey]['workforce_roles'])))
                : [];

            if (in_array('manager', $roles, true)) {
                continue;
            }

            $roles[] = 'manager';
            $profiles[$profileKey]['workforce_roles'] = $roles;
            $profiles[$profileKey]['updated_at'] = $now;
            $managerProfileChanges++;
        }

        $accessDocument['departments'] = is_array($accessDocument['departments'] ?? null)
            ? $accessDocument['departments']
            : [];
        $departmentChanges = 0;

        foreach ($departments as $name => $parent) {
            $name = trim((string) $name);
            $parent = trim((string) $parent);
            $existing = is_array($accessDocument['departments'][$name] ?? null)
                ? $accessDocument['departments'][$name]
                : [];
            $next = [
                'name' => $name,
                'parent' => $parent,
                'created_at' => (string) ($existing['created_at'] ?? $now),
            ];

            if ($existing !== $next) {
                $accessDocument['departments'][$name] = $next;
                $departmentChanges++;
            }
        }

        $accessDocument['department_policies'] = is_array($accessDocument['department_policies'] ?? null)
            ? $accessDocument['department_policies']
            : [];
        $policyChanges = 0;

        foreach ($policies as $department => $managerEmail) {
            $policy = [
                'manager_approval_count' => 1,
                'manager_1_email' => strtolower(trim((string) $managerEmail)),
                'manager_2_email' => '',
                'hr_email' => $hrEmail,
            ];

            if (($accessDocument['department_policies'][$department] ?? null) !== $policy) {
                $accessDocument['department_policies'][$department] = $policy;
                $policyChanges++;
            }
        }

        $accessDocument['user_permissions'] = is_array($accessDocument['user_permissions'] ?? null)
            ? $accessDocument['user_permissions']
            : [];
        $managerPermissionChanges = 0;

        foreach ($managerEmails as $managerEmail) {
            $permissions = is_array($accessDocument['user_permissions'][$managerEmail] ?? null)
                ? array_values(array_unique(array_map('strval', $accessDocument['user_permissions'][$managerEmail])))
                : [];
            $nextPermissions = array_values(array_unique(array_merge($permissions, self::MANAGER_PERMISSIONS)));

            if ($nextPermissions === $permissions) {
                continue;
            }

            $accessDocument['user_permissions'][$managerEmail] = $nextPermissions;
            $managerPermissionChanges++;
        }

        $profileDocument['profiles'] = $profiles;

        return [
            'mode' => $applied ? 'applied' : 'preview',
            'profile_count_before' => count($profiles),
            'profile_count_after' => count($profileDocument['profiles']),
            'assignments_requested' => count($assignments),
            'assignments_changed' => $assignmentChanges,
            'assignments_unchanged' => count($assignments) - $assignmentChanges,
            'departments_requested' => count($departments),
            'departments_changed' => $departmentChanges,
            'policies_requested' => count($policies),
            'policies_changed' => $policyChanges,
            'managers_requested' => count($managerEmails),
            'manager_profiles_changed' => $managerProfileChanges,
            'manager_permissions_changed' => $managerPermissionChanges,
            'unmatched' => 0,
        ];
    }

    private function profileWithEmailExists(array $profiles, string $email): bool
    {
        return $this->profileKeyForEmail($profiles, $email) !== null;
    }

    private function profileKeyForEmail(array $profiles, string $email): ?string
    {
        $email = strtolower(trim($email));

        foreach ($profiles as $profileKey => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            if (strtolower(trim((string) ($profile['email'] ?? ''))) === $email) {
                return (string) $profileKey;
            }
        }

        return null;
    }

    private function normalizedName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
    }

    private function profilesPath(): string
    {
        return $this->profilePath ?? APP_ROOT . '/storage/user-profiles.json';
    }

    private function accessControlPath(): string
    {
        return $this->accessPath ?? APP_ROOT . '/storage/access-control.json';
    }
}
