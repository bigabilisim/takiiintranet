<?php

namespace App\Core;

class AccessControl
{
    private const VERSION = 18;
    private const STATE_KEY = 'access_control';
    private const ADMIN_EMAIL = 'bilal@bigabilisim.com';
    private const HR_EMAIL = 'y.ekici@takii.com.tr';
    private const DEPARTMENT_MANAGER_PERMISSIONS = [
        'module.leave.access',
        'leave.request.approve.department',
    ];
    private const HR_APPROVER_PERMISSIONS = [
        'module.leave.access',
        'leave.request.manage.hr',
    ];
    private const WORKFORCE_ROLE_PERMISSIONS = [
        'hr' => [
            'module.leave.access',
            'leave.request.manage.hr',
            'leave.policy.manage',
            'leave.request.cancel',
            'module.personnel.access',
            'personnel.read',
            'personnel.write',
            'personnel.delete',
            'personnel.export',
            'module.shift.access',
            'shift.manage',
        ],
        'hr_assistant' => [
            'module.leave.access',
            'leave.request.manage.hr',
            'module.personnel.access',
            'personnel.read',
            'personnel.write',
            'personnel.export',
        ],
        'hr_assistant_antalya' => [
            'module.leave.access',
            'leave.request.manage.hr',
            'module.personnel.access',
            'personnel.read',
            'personnel.write',
            'personnel.export',
        ],
        'hr_assistant_bursa' => [
            'module.leave.access',
            'leave.request.manage.hr',
            'module.personnel.access',
            'personnel.read',
            'personnel.write',
            'personnel.export',
        ],
        'manager' => [
            'module.leave.access',
            'leave.request.approve.department',
            'module.personnel.access',
            'personnel.read',
            'personnel.write',
        ],
        'shift_planner' => [
            'module.shift.access',
            'shift.manage',
        ],
    ];
    private array $demoUsers;

    public function __construct(
        array $demoUsers,
        private readonly array $modules,
        private readonly StateStore $stateStore,
    ) {
        $this->demoUsers = $demoUsers;
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $this->ensureSeeded();
    }

    public function users(): array
    {
        return array_values($this->usersByIdentity());
    }

    public function usersByIdentity(): array
    {
        $users = [];

        foreach ($this->demoUsers as $email => $user) {
            $users[$email] = array_merge($user, [
                'email' => $email,
                'name' => $user['name'],
                'role' => $user['role'],
                'department' => $user['department'],
                'permissions' => $this->permissionsFor($email),
                'is_system_admin' => in_array('*', $user['permissions'], true),
            ]);
        }

        return $users;
    }

    public function replaceDirectoryUsers(array $users): void
    {
        $this->demoUsers = $users;
    }

    public function isApprovalAssignee(string $identity): bool
    {
        if ($identity === '') {
            return false;
        }

        foreach ($this->departmentPolicies() as $policy) {
            foreach (['manager_1_email', 'manager_2_email', 'hr_email'] as $field) {
                if ((string) ($policy[$field] ?? '') === $identity) {
                    return true;
                }
            }
        }

        return false;
    }

    public function migrateUserIdentity(
        string $oldIdentity,
        string $newIdentity,
        array $fallbackPermissions = []
    ): array {
        if ($oldIdentity === '' || $newIdentity === '' || $oldIdentity === $newIdentity) {
            return ['permission_keys' => 0, 'approval_references' => 0];
        }

        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $data = $this->loadData();
        $permissions = is_array($data['user_permissions'] ?? null) ? $data['user_permissions'] : [];

        if (array_key_exists($newIdentity, $permissions)) {
            throw new \RuntimeException('The target identity already has an access-control record.');
        }

        $sourcePermissions = is_array($permissions[$oldIdentity] ?? null)
            ? $permissions[$oldIdentity]
            : $fallbackPermissions;
        unset($permissions[$oldIdentity]);
        $permissions[$newIdentity] = array_values(array_unique(array_map('strval', $sourcePermissions)));
        $data['user_permissions'] = $permissions;
        $approvalReferences = 0;
        $data['department_policies'] = IdentityReferenceRewriter::replaceValues(
            is_array($data['department_policies'] ?? null) ? $data['department_policies'] : [],
            $oldIdentity,
            $newIdentity,
            $approvalReferences
        );
        $this->saveData($data);

        return [
            'permission_keys' => 1,
            'approval_references' => $approvalReferences,
        ];
    }

    public function departments(): array
    {
        return $this->departmentNamesFromData($this->data());
    }

    public function departmentOptions(): array
    {
        return array_map(
            fn (array $node): array => [
                'name' => $node['name'],
                'label' => str_repeat('-- ', (int) ($node['level'] ?? 0)) . $node['name'],
                'parent' => $node['parent'] ?? '',
                'level' => (int) ($node['level'] ?? 0),
            ],
            $this->departmentHierarchy()
        );
    }

    public function departmentHierarchy(): array
    {
        return $this->departmentHierarchyFromData($this->data());
    }

    public function departmentParents(): array
    {
        return $this->departmentParentsFromData($this->data());
    }

    public function departmentChildCounts(): array
    {
        return $this->departmentChildCountsFromData($this->data());
    }

    public function departmentUserCounts(): array
    {
        $counts = array_fill_keys($this->departments(), 0);

        foreach ($this->demoUsers as $user) {
            $department = (string) ($user['department'] ?? '');

            if ($department === '') {
                continue;
            }

            $counts[$department] = (int) ($counts[$department] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function permissionsFor(string $email): array
    {
        $permissions = $this->data()['user_permissions'] ?? [];

        if (!isset($permissions[$email])) {
            return $this->expandPermissions($this->demoUsers[$email]['permissions'] ?? []);
        }

        return $this->expandPermissions($permissions[$email]);
    }

    public function setUserPermissions(string $email, array $permissions): void
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());

        if (!isset($this->demoUsers[$email])) {
            return;
        }

        $selected = $this->expandPermissions($permissions);

        if ($this->isSystemAdmin($email) && !in_array('admin.company.manage', $selected, true)) {
            $selected[] = 'admin.company.manage';
        }

        if (!$this->isSystemAdmin($email)) {
            $requiredPermissions = $this->withRequiredPermissions($email, $this->demoUsers[$email], $selected);
            $selected = $this->expandPermissions($this->withoutUnauthorizedShiftPermissions($email, $this->demoUsers[$email], $requiredPermissions));
        }

        $data = $this->data();
        $data['user_permissions'][$email] = array_values(array_unique($selected));
        $this->saveData($data);

        $currentUser = Session::get('user');

        if (is_array($currentUser) && ($currentUser['email'] ?? '') === $email) {
            $currentUser['permissions'] = $data['user_permissions'][$email];
            Session::put('user', $currentUser);
        }
    }

    public function usersWithWorkforceRole(string $role): array
    {
        $users = [];

        foreach ($this->users() as $user) {
            $roles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];

            if (!in_array($role, $roles, true)) {
                continue;
            }

            $email = (string) ($user['email'] ?? '');

            if ($email === '') {
                continue;
            }

            $users[] = $user;
        }

        return $users;
    }

    public function permissionCatalog(): array
    {
        $catalog = [];

        foreach ($this->modules as $module) {
            $catalog[] = [
                'group_key' => 'admin.permission_group.modules',
                'permission' => $module['permission'],
                'label_key' => $module['title_key'],
                'parent_permission' => '',
            ];
        }

        $parents = $this->permissionParents();

        foreach ($this->workflowPermissions() as $permission => $labelKey) {
            $catalog[] = [
                'group_key' => 'admin.permission_group.workflow',
                'permission' => $permission,
                'label_key' => $labelKey,
                'parent_permission' => $parents[$permission] ?? '',
            ];
        }

        return $catalog;
    }

    public function departmentPolicies(): array
    {
        return $this->data()['department_policies'] ?? [];
    }

    public function departmentPolicy(string $department): array
    {
        $data = $this->data();
        $department = $this->cleanDepartmentName($department);
        $policies = $data['department_policies'] ?? [];

        if (isset($policies[$department]) && is_array($policies[$department])) {
            return $policies[$department];
        }

        foreach ($this->departmentParentChainFromData($data, $department) as $parentDepartment) {
            if (isset($policies[$parentDepartment]) && is_array($policies[$parentDepartment])) {
                return $policies[$parentDepartment];
            }
        }

        return $this->defaultPolicyFor($department);
    }

    public function setDepartmentPolicy(string $department, array $policy): bool
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $department = $this->cleanDepartmentName($department);

        if (!in_array($department, $this->departments(), true)) {
            return false;
        }

        $data = $this->data();
        $data['department_policies'][$department] = [
            'manager_approval_count' => (int) ($policy['manager_approval_count'] ?? 1) === 2 ? 2 : 1,
            'manager_1_email' => $this->validUserEmail((string) ($policy['manager_1_email'] ?? '')),
            'manager_2_email' => $this->validUserEmail((string) ($policy['manager_2_email'] ?? '')),
            'hr_email' => $this->validUserEmail((string) ($policy['hr_email'] ?? '')),
        ];

        if ($data['department_policies'][$department]['manager_approval_count'] === 1) {
            $data['department_policies'][$department]['manager_2_email'] = '';
        }

        $this->ensureDepartmentPolicyAssigneePermissions($data, $data['department_policies'][$department]);
        $this->saveData($data);

        return true;
    }

    public function createDepartment(string $name, string $parent = ''): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $name = $this->cleanDepartmentName($name);
        $parent = $this->cleanDepartmentName($parent);

        if ($name === '') {
            return ['ok' => false, 'message' => 'admin.flash.department_invalid'];
        }

        $data = $this->data();
        $departmentNames = $this->departmentNamesFromData($data);

        if (in_array($name, $departmentNames, true)) {
            return ['ok' => false, 'message' => 'admin.flash.department_exists'];
        }

        if ($parent !== '' && (!in_array($parent, $departmentNames, true) || $parent === $name)) {
            return ['ok' => false, 'message' => 'admin.flash.department_parent_invalid'];
        }

        $data['departments'][$name] = [
            'name' => $name,
            'parent' => $parent,
            'created_at' => date('Y-m-d H:i'),
        ];
        $data['department_policies'][$name] = $parent !== ''
            ? $this->departmentPolicyFromData($data, $parent)
            : $this->defaultPolicyFor($name);
        $this->saveData($data);

        return ['ok' => true, 'message' => 'admin.flash.department_created', 'department' => $name, 'parent' => $parent];
    }

    public function deleteDepartment(string $name): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $name = $this->cleanDepartmentName($name);
        $data = $this->data();

        if ($name === '' || !in_array($name, $this->departmentNamesFromData($data), true)) {
            return ['ok' => false, 'message' => 'admin.flash.department_not_found'];
        }

        $userCount = (int) ($this->departmentUserCounts()[$name] ?? 0);

        if ($userCount > 0) {
            return ['ok' => false, 'message' => 'admin.flash.department_in_use', 'department' => $name, 'user_count' => $userCount];
        }

        $childCount = (int) ($this->departmentChildCountsFromData($data)[$name] ?? 0);

        if ($childCount > 0) {
            return ['ok' => false, 'message' => 'admin.flash.department_has_children', 'department' => $name, 'child_count' => $childCount];
        }

        unset($data['departments'][$name], $data['department_policies'][$name]);
        $this->saveData($data);

        return ['ok' => true, 'message' => 'admin.flash.department_deleted', 'department' => $name];
    }

    public function setDepartmentParent(string $department, string $parent): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $department = $this->cleanDepartmentName($department);
        $parent = $this->cleanDepartmentName($parent);
        $data = $this->data();
        $departmentNames = $this->departmentNamesFromData($data);

        if ($department === '' || !in_array($department, $departmentNames, true)) {
            return ['ok' => false, 'message' => 'admin.flash.department_not_found'];
        }

        if ($parent === '' || $parent === $department || !in_array($parent, $departmentNames, true)) {
            return ['ok' => false, 'message' => 'admin.flash.department_parent_invalid'];
        }

        $parents = $this->departmentParentsFromData($data);
        $parents[$department] = $parent;
        $seen = [$department => true];
        $cursor = $parent;

        while ($cursor !== '') {
            if (isset($seen[$cursor])) {
                return ['ok' => false, 'message' => 'admin.flash.department_parent_invalid'];
            }

            $seen[$cursor] = true;
            $cursor = $parents[$cursor] ?? '';
        }

        $existing = is_array($data['departments'][$department] ?? null) ? $data['departments'][$department] : [];
        $data['departments'][$department] = [
            'name' => $department,
            'parent' => $parent,
            'created_at' => (string) ($existing['created_at'] ?? ''),
        ];

        $this->saveData($data);

        return ['ok' => true, 'message' => 'leave_policy.flash.parent_saved', 'department' => $department, 'parent' => $parent];
    }

    public function managedPermissionSlugs(): array
    {
        $permissions = array_map(
            fn (array $module): string => $module['permission'],
            $this->modules
        );

        return array_values(array_unique(array_merge(
            $permissions,
            array_keys($this->workflowPermissions())
        )));
    }

    private function ensureSeeded(): void
    {
        $this->data();
    }

    private function data(): array
    {
        $data = $this->loadData();

        if (!isset($data['user_permissions']) || !is_array($data['user_permissions'])) {
            return $this->seedData();
        }

        if (!isset($data['department_policies']) || !is_array($data['department_policies'])) {
            return $this->seedData();
        }

        $dirty = false;

        $previousVersion = (int) ($data['version'] ?? 0);

        foreach ($this->demoUsers as $email => $user) {
            $currentPermissions = $data['user_permissions'][$email] ?? ($user['permissions'] ?? []);

            if ($previousVersion > 0 && $previousVersion < 7 && !$this->isSystemAdmin($email)) {
                $currentPermissions = $this->migratePersonnelPermissions($email, $user, is_array($currentPermissions) ? $currentPermissions : []);
            }

            if ($previousVersion > 0 && $previousVersion < 9 && !$this->isSystemAdmin($email)) {
                $currentPermissions = $this->migratePersonnelExportPermission($email, $user, is_array($currentPermissions) ? $currentPermissions : []);
            }

            if ($previousVersion > 0 && $previousVersion < 16 && !$this->isSystemAdmin($email)) {
                $currentPermissions = $this->migratePasswordOnlyLeaveAccess($user, is_array($currentPermissions) ? $currentPermissions : []);
            }

            if (!$this->isSystemAdmin($email)) {
                $currentPermissions = $this->withRequiredPermissions($email, $user, is_array($currentPermissions) ? $currentPermissions : []);
                $currentPermissions = $this->withoutUnauthorizedShiftPermissions($email, $user, $currentPermissions);
            }

            $expandedPermissions = $this->isSystemAdmin($email)
                ? $this->managedPermissionSlugs()
                : $this->expandPermissions(is_array($currentPermissions) ? $currentPermissions : []);

            if (($data['user_permissions'][$email] ?? []) !== $expandedPermissions) {
                $data['user_permissions'][$email] = $expandedPermissions;
                $dirty = true;
            }
        }

        foreach (array_keys($data['user_permissions'] ?? []) as $email) {
            if (!isset($this->demoUsers[$email])) {
                unset($data['user_permissions'][$email]);
                $dirty = true;
            }
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            $data['version'] = self::VERSION;
            $dirty = true;
        }

        if (!isset($data['departments']) || !is_array($data['departments'])) {
            $data['departments'] = [];
            $dirty = true;
        }

        $normalizedDepartments = $this->normalizeDepartments($data);

        if (($data['departments'] ?? []) !== $normalizedDepartments) {
            $data['departments'] = $normalizedDepartments;
            $dirty = true;
        }

        $departmentNames = $this->departmentNamesFromData($data);

        foreach ($departmentNames as $department) {
            if (!isset($data['department_policies'][$department]) || !is_array($data['department_policies'][$department])) {
                $data['department_policies'][$department] = $this->departmentPolicyFromData($data, $department);
                $dirty = true;
            }

            $normalizedPolicy = $this->normalizePolicy($department, $data['department_policies'][$department]);

            if ($data['department_policies'][$department] !== $normalizedPolicy) {
                $data['department_policies'][$department] = $normalizedPolicy;
                $dirty = true;
            }

            if ($this->ensureDepartmentPolicyAssigneePermissions($data, $data['department_policies'][$department])) {
                $dirty = true;
            }
        }

        foreach (array_keys($data['department_policies']) as $department) {
            if (!in_array($department, $departmentNames, true)) {
                unset($data['department_policies'][$department]);
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->saveData($data);
        }

        return $data;
    }

    private function seedData(): array
    {
        $permissions = [];

        foreach ($this->demoUsers as $email => $user) {
            $permissions[$email] = $this->expandPermissions($this->withRequiredPermissions($email, $user, $user['permissions']));
        }

        $policies = [];

        foreach ($this->userDepartments() as $department) {
            $policies[$department] = $this->defaultPolicyFor($department);
        }

        $data = [
            'version' => self::VERSION,
            'departments' => [],
            'user_permissions' => $permissions,
            'department_policies' => $policies,
        ];

        foreach ($data['department_policies'] as $department => $policy) {
            $data['department_policies'][$department] = $this->normalizePolicy($department, $policy);
            $this->ensureDepartmentPolicyAssigneePermissions($data, $data['department_policies'][$department]);
        }

        $this->saveData($data);

        return $data;
    }

    private function loadData(): array
    {
        return $this->stateStore->read(self::STATE_KEY, $this->dataPath());
    }

    private function userDepartments(): array
    {
        $departments = [];

        foreach ($this->demoUsers as $user) {
            $department = $this->cleanDepartmentName((string) ($user['department'] ?? ''));

            if ($department !== '') {
                $departments[$department] = $department;
            }
        }

        sort($departments);

        return array_values($departments);
    }

    private function departmentNamesFromData(array $data): array
    {
        $departments = array_fill_keys($this->userDepartments(), true);

        foreach (($data['departments'] ?? []) as $key => $department) {
            $name = is_array($department) ? (string) ($department['name'] ?? $key) : (string) $department;
            $name = $this->cleanDepartmentName($name);

            if ($name !== '') {
                $departments[$name] = true;
            }
        }

        $names = array_keys($departments);
        sort($names);

        return $names;
    }

    private function normalizeDepartments(array $data): array
    {
        $normalized = [];

        foreach (($data['departments'] ?? []) as $key => $department) {
            $name = is_array($department) ? (string) ($department['name'] ?? $key) : (string) $department;
            $name = $this->cleanDepartmentName($name);

            if ($name === '') {
                continue;
            }

            $parent = is_array($department) ? $this->cleanDepartmentName((string) ($department['parent'] ?? '')) : '';

            $normalized[$name] = [
                'name' => $name,
                'parent' => $parent,
                'created_at' => is_array($department) ? (string) ($department['created_at'] ?? '') : '',
            ];
        }

        $knownDepartments = array_fill_keys(array_merge($this->userDepartments(), array_keys($normalized)), true);

        foreach ($normalized as $name => $department) {
            $parent = (string) ($department['parent'] ?? '');

            if ($parent === $name || $parent === '' || !isset($knownDepartments[$parent])) {
                $normalized[$name]['parent'] = '';
                continue;
            }

            $seen = [$name => true];
            $cursor = $parent;

            while ($cursor !== '') {
                if (isset($seen[$cursor])) {
                    $normalized[$name]['parent'] = '';
                    break;
                }

                $seen[$cursor] = true;
                $cursor = (string) ($normalized[$cursor]['parent'] ?? '');
            }
        }

        ksort($normalized);

        return $normalized;
    }

    private function departmentParentsFromData(array $data): array
    {
        $parents = array_fill_keys($this->departmentNamesFromData($data), '');

        foreach (($data['departments'] ?? []) as $key => $department) {
            $name = is_array($department) ? (string) ($department['name'] ?? $key) : (string) $department;
            $name = $this->cleanDepartmentName($name);

            if ($name === '' || !array_key_exists($name, $parents)) {
                continue;
            }

            $parents[$name] = is_array($department) ? $this->cleanDepartmentName((string) ($department['parent'] ?? '')) : '';
        }

        foreach ($parents as $name => $parent) {
            if ($parent === '' || $parent === $name || !array_key_exists($parent, $parents)) {
                $parents[$name] = '';
                continue;
            }

            $seen = [$name => true];
            $cursor = $parent;

            while ($cursor !== '') {
                if (isset($seen[$cursor])) {
                    $parents[$name] = '';
                    break;
                }

                $seen[$cursor] = true;
                $cursor = $parents[$cursor] ?? '';
            }
        }

        ksort($parents);

        return $parents;
    }

    private function departmentChildCountsFromData(array $data): array
    {
        $parents = $this->departmentParentsFromData($data);
        $counts = array_fill_keys(array_keys($parents), 0);

        foreach ($parents as $parent) {
            if ($parent !== '' && array_key_exists($parent, $counts)) {
                $counts[$parent]++;
            }
        }

        return $counts;
    }

    private function departmentHierarchyFromData(array $data): array
    {
        $parents = $this->departmentParentsFromData($data);
        $children = [];

        foreach ($parents as $name => $parent) {
            $children[$parent][] = $name;
        }

        foreach ($children as $parent => $names) {
            natcasesort($names);
            $children[$parent] = array_values($names);
        }

        $ordered = [];
        $visited = [];
        $appendDepartment = function (string $department, int $level) use (&$appendDepartment, &$ordered, &$visited, $parents, $children): void {
            if (isset($visited[$department])) {
                return;
            }

            $visited[$department] = true;
            $ordered[] = [
                'name' => $department,
                'parent' => $parents[$department] ?? '',
                'level' => $level,
                'child_count' => count($children[$department] ?? []),
            ];

            foreach ($children[$department] ?? [] as $childDepartment) {
                $appendDepartment($childDepartment, $level + 1);
            }
        };

        foreach ($children[''] ?? [] as $rootDepartment) {
            $appendDepartment($rootDepartment, 0);
        }

        foreach (array_keys($parents) as $department) {
            $appendDepartment($department, 0);
        }

        return $ordered;
    }

    private function departmentParentChainFromData(array $data, string $department): array
    {
        $parents = $this->departmentParentsFromData($data);
        $chain = [];
        $seen = [$department => true];
        $cursor = $parents[$department] ?? '';

        while ($cursor !== '' && !isset($seen[$cursor])) {
            $chain[] = $cursor;
            $seen[$cursor] = true;
            $cursor = $parents[$cursor] ?? '';
        }

        return $chain;
    }

    private function departmentPolicyFromData(array $data, string $department): array
    {
        $department = $this->cleanDepartmentName($department);
        $policies = $data['department_policies'] ?? [];

        if (isset($policies[$department]) && is_array($policies[$department])) {
            return $this->normalizePolicy($department, $policies[$department]);
        }

        foreach ($this->departmentParentChainFromData($data, $department) as $parentDepartment) {
            if (isset($policies[$parentDepartment]) && is_array($policies[$parentDepartment])) {
                return $this->normalizePolicy($parentDepartment, $policies[$parentDepartment]);
            }
        }

        return $this->defaultPolicyFor($department);
    }

    private function cleanDepartmentName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return substr($name, 0, 100);
    }

    private function saveData(array $data): void
    {
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), $data);
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/access-control.json';
    }

    private function expandPermissions(array $permissions): array
    {
        if (in_array('*', $permissions, true)) {
            return $this->managedPermissionSlugs();
        }

        $managedPermissions = $this->managedPermissionSlugs();
        $selected = array_values(array_intersect($permissions, $managedPermissions));

        foreach ($this->permissionParents() as $permission => $parentPermission) {
            if (in_array($permission, $selected, true) && !in_array($parentPermission, $selected, true)) {
                $selected[] = $parentPermission;
            }
        }

        return array_values(array_filter(
            $managedPermissions,
            fn (string $permission): bool => in_array($permission, $selected, true)
        ));
    }

    private function workflowPermissions(): array
    {
        return [
            'content.announcement.view' => 'admin.permission.content_view',
            'messaging.send' => 'admin.permission.messaging_send',
            'leave.request.create' => 'admin.permission.leave_create',
            'leave.request.approve.department' => 'admin.permission.leave_approve_department',
            'leave.request.manage.hr' => 'admin.permission.leave_manage_hr',
            'leave.policy.manage' => 'admin.permission.leave_policy_manage',
            'leave.request.cancel' => 'admin.permission.leave_cancel',
            'personnel.read' => 'admin.permission.personnel_read',
            'personnel.write' => 'admin.permission.personnel_write',
            'personnel.delete' => 'admin.permission.personnel_delete',
            'personnel.export' => 'admin.permission.personnel_export',
            'shift.manage' => 'admin.permission.shift_manage',
            'documents.view' => 'admin.permission.documents_view',
            'budget.view.own' => 'admin.permission.budget_own',
            'budget.view.department' => 'admin.permission.budget_department',
            'procurement.request.create' => 'admin.permission.procurement_create',
            'procurement.request.approve.department' => 'admin.permission.procurement_department',
            'procurement.request.approve.finance' => 'admin.permission.procurement_finance',
            'templates.manage' => 'admin.permission.templates_manage',
            'content.announcement.publish' => 'admin.permission.content_publish',
            'admin.company.manage' => 'admin.permission.admin_manage',
        ];
    }

    private function permissionParents(): array
    {
        return [
            'content.announcement.view' => 'module.announcements.access',
            'content.announcement.publish' => 'module.announcements.access',
            'messaging.send' => 'module.messages.access',
            'leave.request.create' => 'module.leave.access',
            'leave.request.approve.department' => 'module.leave.access',
            'leave.request.manage.hr' => 'module.leave.access',
            'leave.policy.manage' => 'module.leave.access',
            'leave.request.cancel' => 'module.leave.access',
            'personnel.read' => 'module.personnel.access',
            'personnel.write' => 'module.personnel.access',
            'personnel.delete' => 'module.personnel.access',
            'personnel.export' => 'module.personnel.access',
            'shift.manage' => 'module.shift.access',
            'documents.view' => 'module.documents.access',
            'budget.view.own' => 'module.budget.access',
            'budget.view.department' => 'module.budget.access',
            'procurement.request.create' => 'module.procurement.access',
            'procurement.request.approve.department' => 'module.procurement.access',
            'procurement.request.approve.finance' => 'module.procurement.access',
            'templates.manage' => 'module.templates.access',
        ];
    }

    private function defaultPolicyFor(string $department): array
    {
        $hrIdentity = $this->defaultHrIdentity();

        if ($department === 'Product') {
            return [
                'manager_approval_count' => 1,
                'manager_1_email' => self::ADMIN_EMAIL,
                'manager_2_email' => '',
                'hr_email' => $hrIdentity,
            ];
        }

        return [
            'manager_approval_count' => 1,
            'manager_1_email' => self::ADMIN_EMAIL,
            'manager_2_email' => '',
            'hr_email' => $hrIdentity,
        ];
    }

    private function defaultHrIdentity(): string
    {
        foreach ($this->demoUsers as $identity => $user) {
            $roles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];

            if (in_array('hr', $roles, true)) {
                return (string) $identity;
            }
        }

        return isset($this->demoUsers[self::HR_EMAIL]) ? self::HR_EMAIL : '';
    }

    private function migratePersonnelPermissions(string $email, array $user, array $currentPermissions): array
    {
        $workforceRoles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];
        $permissions = $currentPermissions;

        if ($email === self::HR_EMAIL || in_array('hr', $workforceRoles, true)) {
            return array_values(array_unique(array_merge($permissions, [
                'module.personnel.access',
                'personnel.read',
                'personnel.write',
                'personnel.delete',
                'personnel.export',
            ])));
        }

        if (in_array('manager', $workforceRoles, true)) {
            return array_values(array_unique(array_merge($permissions, [
                'module.personnel.access',
                'personnel.read',
                'personnel.write',
            ])));
        }

        return $permissions;
    }

    private function migratePersonnelExportPermission(string $email, array $user, array $currentPermissions): array
    {
        $workforceRoles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];

        if ($email !== self::HR_EMAIL && !in_array('hr', $workforceRoles, true)) {
            return $currentPermissions;
        }

        return array_values(array_unique(array_merge($currentPermissions, [
            'module.personnel.access',
            'personnel.read',
            'personnel.export',
        ])));
    }

    private function migratePasswordOnlyLeaveAccess(array $user, array $currentPermissions): array
    {
        if (trim((string) ($user['email'] ?? '')) !== '') {
            return $currentPermissions;
        }

        return array_values(array_unique(array_merge($currentPermissions, [
            'module.leave.access',
            'leave.request.create',
        ])));
    }

    private function withRequiredPermissions(string $email, array $user, array $permissions): array
    {
        $permissions = $this->withWorkforceRolePermissions($user, $permissions);

        if (!$this->isHrPersonnelOwner($email, $user)) {
            return $permissions;
        }

        return array_values(array_unique(array_merge($permissions, [
            'module.personnel.access',
            'personnel.read',
            'personnel.write',
            'personnel.delete',
            'personnel.export',
            'module.shift.access',
            'shift.manage',
        ])));
    }

    private function withoutUnauthorizedShiftPermissions(string $email, array $user, array $permissions): array
    {
        if ($this->isSystemAdmin($email) || $this->isShiftModuleOwner($email, $user)) {
            return $permissions;
        }

        return array_values(array_diff($permissions, [
            'module.shift.access',
            'shift.manage',
        ]));
    }

    private function withWorkforceRolePermissions(array $user, array $permissions): array
    {
        $roles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];

        foreach ($roles as $role) {
            $role = (string) $role;

            if (!isset(self::WORKFORCE_ROLE_PERMISSIONS[$role])) {
                continue;
            }

            $permissions = array_merge($permissions, self::WORKFORCE_ROLE_PERMISSIONS[$role]);
        }

        return array_values(array_unique($permissions));
    }

    private function isHrPersonnelOwner(string $email, array $user): bool
    {
        $workforceRoles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];

        return $email === self::HR_EMAIL
            || in_array('hr', $workforceRoles, true);
    }

    private function isShiftModuleOwner(string $email, array $user): bool
    {
        $workforceRoles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];

        return $email === self::HR_EMAIL
            || in_array('hr', $workforceRoles, true)
            || in_array('shift_planner', $workforceRoles, true);
    }

    private function validUserEmail(string $email): string
    {
        return isset($this->demoUsers[$email]) ? $email : '';
    }

    private function normalizePolicy(string $department, array $policy): array
    {
        $defaults = $this->defaultPolicyFor($department);
        $managerCount = (int) ($policy['manager_approval_count'] ?? $defaults['manager_approval_count']);
        $managerCount = $managerCount === 2 ? 2 : 1;
        $manager1 = $this->validUserEmail((string) ($policy['manager_1_email'] ?? ''));
        $manager2 = $this->validUserEmail((string) ($policy['manager_2_email'] ?? ''));
        $hr = $this->validUserEmail((string) ($policy['hr_email'] ?? ''));

        if ($manager1 === '') {
            $manager1 = $this->validUserEmail((string) ($defaults['manager_1_email'] ?? ''));
        }

        if ($manager2 === '') {
            $manager2 = $this->validUserEmail((string) ($defaults['manager_2_email'] ?? ''));
        }

        if ($hr === '') {
            $hr = $this->validUserEmail((string) ($defaults['hr_email'] ?? ''));
        }

        if ($managerCount === 1 || $manager2 === '' || $manager2 === $manager1) {
            $managerCount = 1;
            $manager2 = '';
        }

        return [
            'manager_approval_count' => $managerCount,
            'manager_1_email' => $manager1,
            'manager_2_email' => $manager2,
            'hr_email' => $hr,
        ];
    }

    private function ensureDepartmentPolicyAssigneePermissions(array &$data, array $policy): bool
    {
        $dirty = false;

        foreach (['manager_1_email', 'manager_2_email'] as $key) {
            $email = (string) ($policy[$key] ?? '');

            if ($email !== '' && $this->grantUserPermissions($data, $email, self::DEPARTMENT_MANAGER_PERMISSIONS)) {
                $dirty = true;
            }
        }

        $hrEmail = (string) ($policy['hr_email'] ?? '');

        if ($hrEmail !== '' && $this->grantUserPermissions($data, $hrEmail, self::HR_APPROVER_PERMISSIONS)) {
            $dirty = true;
        }

        return $dirty;
    }

    private function grantUserPermissions(array &$data, string $email, array $permissions): bool
    {
        if (!isset($this->demoUsers[$email]) || $this->isSystemAdmin($email)) {
            return false;
        }

        $currentPermissions = $data['user_permissions'][$email] ?? ($this->demoUsers[$email]['permissions'] ?? []);
        $currentPermissions = is_array($currentPermissions) ? $currentPermissions : [];
        $expanded = $this->expandPermissions(array_merge($currentPermissions, $permissions));

        if (($data['user_permissions'][$email] ?? []) === $expanded) {
            return false;
        }

        $data['user_permissions'][$email] = $expanded;

        return true;
    }

    private function isSystemAdmin(string $email): bool
    {
        return in_array('*', $this->demoUsers[$email]['permissions'] ?? [], true);
    }
}
