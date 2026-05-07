<?php

namespace App\Core;

class AccessControl
{
    private const VERSION = 13;

    public function __construct(
        private readonly array $demoUsers,
        private readonly array $modules,
    ) {
        $this->ensureSeeded();
    }

    public function users(): array
    {
        $users = [];

        foreach ($this->demoUsers as $email => $user) {
            $users[] = array_merge($user, [
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

    public function departments(): array
    {
        return $this->departmentNamesFromData($this->data());
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
        if (!isset($this->demoUsers[$email])) {
            return;
        }

        $selected = $this->expandPermissions($permissions);

        if ($this->isSystemAdmin($email) && !in_array('admin.company.manage', $selected, true)) {
            $selected[] = 'admin.company.manage';
        }

        if (!$this->isSystemAdmin($email)) {
            $selected = $this->expandPermissions($this->withRequiredPermissions($email, $this->demoUsers[$email], $selected));
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
        $policies = $this->departmentPolicies();

        return $policies[$department] ?? $this->defaultPolicyFor($department);
    }

    public function setDepartmentPolicy(string $department, array $policy): bool
    {
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

        $this->saveData($data);

        return true;
    }

    public function createDepartment(string $name): array
    {
        $name = $this->cleanDepartmentName($name);

        if ($name === '') {
            return ['ok' => false, 'message' => 'admin.flash.department_invalid'];
        }

        $data = $this->data();

        if (in_array($name, $this->departmentNamesFromData($data), true)) {
            return ['ok' => false, 'message' => 'admin.flash.department_exists'];
        }

        $data['departments'][$name] = [
            'name' => $name,
            'created_at' => date('Y-m-d H:i'),
        ];
        $data['department_policies'][$name] = $this->defaultPolicyFor($name);
        $this->saveData($data);

        return ['ok' => true, 'message' => 'admin.flash.department_created', 'department' => $name];
    }

    public function deleteDepartment(string $name): array
    {
        $name = $this->cleanDepartmentName($name);
        $data = $this->data();

        if ($name === '' || !in_array($name, $this->departmentNamesFromData($data), true)) {
            return ['ok' => false, 'message' => 'admin.flash.department_not_found'];
        }

        $userCount = (int) ($this->departmentUserCounts()[$name] ?? 0);

        if ($userCount > 0) {
            return ['ok' => false, 'message' => 'admin.flash.department_in_use', 'department' => $name, 'user_count' => $userCount];
        }

        unset($data['departments'][$name], $data['department_policies'][$name]);
        $this->saveData($data);

        return ['ok' => true, 'message' => 'admin.flash.department_deleted', 'department' => $name];
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

            if (!$this->isSystemAdmin($email)) {
                $currentPermissions = $this->withRequiredPermissions($email, $user, is_array($currentPermissions) ? $currentPermissions : []);
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

        $departmentNames = $this->departmentNamesFromData($data);

        foreach ($departmentNames as $department) {
            if (!isset($data['department_policies'][$department]) || !is_array($data['department_policies'][$department])) {
                $data['department_policies'][$department] = $this->defaultPolicyFor($department);
                $dirty = true;
            }

            $normalizedPolicy = $this->normalizePolicy($department, $data['department_policies'][$department]);

            if ($data['department_policies'][$department] !== $normalizedPolicy) {
                $data['department_policies'][$department] = $normalizedPolicy;
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

        $this->saveData($data);

        return $data;
    }

    private function loadData(): array
    {
        $path = $this->dataPath();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
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

    private function cleanDepartmentName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return substr($name, 0, 100);
    }

    private function saveData(array $data): void
    {
        $path = $this->dataPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
            'personnel.read' => 'admin.permission.personnel_read',
            'personnel.write' => 'admin.permission.personnel_write',
            'personnel.delete' => 'admin.permission.personnel_delete',
            'personnel.export' => 'admin.permission.personnel_export',
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
            'personnel.read' => 'module.personnel.access',
            'personnel.write' => 'module.personnel.access',
            'personnel.delete' => 'module.personnel.access',
            'personnel.export' => 'module.personnel.access',
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
        $managerEmail = $this->defaultManagerEmail();
        $hrEmail = $this->hrEmail();

        if ($department === 'Product') {
            return [
                'manager_approval_count' => 1,
                'manager_1_email' => $managerEmail,
                'manager_2_email' => '',
                'hr_email' => $hrEmail,
            ];
        }

        return [
            'manager_approval_count' => 1,
            'manager_1_email' => $managerEmail,
            'manager_2_email' => '',
            'hr_email' => $hrEmail,
        ];
    }

    private function migratePersonnelPermissions(string $email, array $user, array $currentPermissions): array
    {
        $role = strtolower((string) ($user['role'] ?? ''));
        $permissions = $currentPermissions;

        if ($email === $this->hrEmail() || str_contains($role, 'hr')) {
            return array_values(array_unique(array_merge($permissions, [
                'module.personnel.access',
                'personnel.read',
                'personnel.write',
                'personnel.delete',
                'personnel.export',
            ])));
        }

        if (str_contains($role, 'manager')) {
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
        $role = strtolower((string) ($user['role'] ?? ''));

        if ($email !== $this->hrEmail() && !str_contains($role, 'hr')) {
            return $currentPermissions;
        }

        return array_values(array_unique(array_merge($currentPermissions, [
            'module.personnel.access',
            'personnel.read',
            'personnel.export',
        ])));
    }

    private function withRequiredPermissions(string $email, array $user, array $permissions): array
    {
        if (!$this->isHrPersonnelOwner($email, $user)) {
            return $permissions;
        }

        return array_values(array_unique(array_merge($permissions, [
            'module.personnel.access',
            'personnel.read',
            'personnel.write',
            'personnel.delete',
            'personnel.export',
        ])));
    }

    private function isHrPersonnelOwner(string $email, array $user): bool
    {
        $role = strtolower((string) ($user['role'] ?? ''));

        return $email === $this->hrEmail()
            || str_contains($role, 'hr');
    }

    private function defaultManagerEmail(): string
    {
        foreach ($this->demoUsers as $email => $user) {
            if (in_array('*', $user['permissions'] ?? [], true)) {
                return (string) $email;
            }
        }

        return (string) array_key_first($this->demoUsers);
    }

    private function hrEmail(): string
    {
        $configured = strtolower(trim((string) getenv('APP_HR_EMAIL')));

        if ($configured !== '' && isset($this->demoUsers[$configured])) {
            return $configured;
        }

        foreach ($this->demoUsers as $email => $user) {
            $role = strtolower((string) ($user['role'] ?? ''));
            $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];

            if (in_array('leave.request.manage.hr', $permissions, true) || str_contains($role, 'hr')) {
                return (string) $email;
            }
        }

        return '';
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

    private function isSystemAdmin(string $email): bool
    {
        return in_array('*', $this->demoUsers[$email]['permissions'] ?? [], true);
    }
}
