<?php

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\AuditLogStore;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\LocationScope;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\UserIdentityMigrationService;
use App\Core\UserProfileStore;
use App\Core\View;
use App\Modules\Auth\PersonnelCredentialService;
use App\Modules\Shift\ShiftStore;

class PersonnelController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly UserProfileStore $userProfiles,
        private readonly AccessControl $accessControl,
        private readonly AuditLogStore $auditLog,
        private readonly ShiftStore $shiftStore,
        private readonly UserIdentityMigrationService $identityMigration,
        private readonly PersonnelCredentialService $credentials,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->canRead()) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $personnel = $this->sortedProfiles();
        $temporaryCredential = Session::pullFlash('personnel_credential', []);
        $headers = is_array($temporaryCredential) && (string) ($temporaryCredential['password'] ?? '') !== ''
            ? ['Cache-Control' => 'no-store, no-cache, must-revalidate', 'Pragma' => 'no-cache']
            : [];

        return new Response($this->view->render('personnel/index', [
            'title' => 'module.personnel.title',
            'personnel' => $personnel,
            'departments' => $this->visibleDepartmentNames(),
            'departmentOptions' => $this->visibleDepartmentOptions(),
            'locationOptions' => LocationScope::visibleOptions($this->auth->user() ?? []),
            'canWritePersonnel' => $this->auth->can('personnel.write'),
            'canDeletePersonnel' => $this->auth->can('personnel.delete'),
            'canExportPersonnel' => $this->canExport(),
            'canManageCredentials' => $this->canManageCredentials(),
            'temporaryCredential' => $temporaryCredential,
            'deletableEmails' => $this->deletableEmails(),
            'personnelGroupCounts' => $this->personnelGroupCounts($personnel),
            'shiftOptions' => $this->shiftStore->enabledTemplates(),
            'shiftTemplates' => $this->shiftStore->templates(),
        ]), 200, $headers);
    }

    public function export(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->canExport()) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $profiles = $this->sortedProfiles();
        $this->auditLog->record($this->auth->user() ?? [], 'personnel.exported', 'personnel', 'csv', [
            'record_count' => (string) count($profiles),
        ]);

        return new Response($this->userProfiles->exportProfilesCsv($profiles), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="personnel-export-' . date('Ymd-His') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function exportExcel(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->canExport()) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $profiles = $this->sortedProfiles();
        $content = $this->userProfiles->exportProfilesXlsx($profiles);

        if ($content === '') {
            Session::flash('error', 'personnel.flash.export_failed');

            return Response::redirect('/module/personnel');
        }

        $this->auditLog->record($this->auth->user() ?? [], 'personnel.exported', 'personnel', 'xlsx', [
            'record_count' => (string) count($profiles),
        ]);

        return new Response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="personnel-export-' . date('Ymd-His') . '.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function create(Request $request): Response
    {
        if (!$this->canMutate('personnel.write', $request)) {
            return Response::redirect('/module/personnel');
        }

        if (!$this->canUseDepartment((string) $request->input('department', ''))) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        if (!$this->canUseLocation((string) $request->input('location', ''))) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        $result = $this->userProfiles->createProfile($request->all());

        if ($result['ok']) {
            $profileKey = (string) ($result['profile_key'] ?? '');
            $profile = $this->userProfiles->find($profileKey);
            $this->auditLog->record($this->auth->user() ?? [], 'personnel.profile_created', 'personnel', $profileKey, [
                'name' => (string) ($profile['name'] ?? ($result['name'] ?? '')),
                'department' => (string) ($profile['department'] ?? ''),
                'email' => (string) ($profile['email'] ?? ($result['email'] ?? '')),
                'username' => (string) ($profile['username'] ?? ($result['username'] ?? '')),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/personnel');
    }

    public function update(Request $request): Response
    {
        if (!$this->canMutate('personnel.write', $request)) {
            return Response::redirect('/module/personnel');
        }

        $profileKey = (string) $request->input('profile_key', $request->input('email'));
        $before = $this->userProfiles->find($profileKey);

        if ($before !== null && !$this->canViewProfile($before)) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        if (!$this->canUseDepartment((string) $request->input('department', (string) ($before['department'] ?? '')))) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        if (!$this->canUseLocation((string) $request->input('location', (string) ($before['location'] ?? '')))) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        $result = $this->identityMigration->updateProfile($profileKey, $request->all());

        if ($result['ok']) {
            $updatedProfileKey = (string) ($result['profile_key'] ?? $profileKey);
            $after = $this->userProfiles->find($updatedProfileKey);
            $this->auditLog->record($this->auth->user() ?? [], 'personnel.profile_updated', 'personnel', $updatedProfileKey, [
                'before_name' => (string) ($before['name'] ?? ''),
                'after_name' => (string) ($after['name'] ?? ''),
                'before_department' => (string) ($before['department'] ?? ''),
                'after_department' => (string) ($after['department'] ?? ''),
                'before_email' => (string) ($result['old_email'] ?? ($before['email'] ?? '')),
                'after_email' => (string) ($result['new_email'] ?? ($after['email'] ?? '')),
                'before_username' => (string) ($before['username'] ?? ''),
                'after_username' => (string) ($after['username'] ?? ''),
                'identity_migrated' => !empty($result['identity_migrated']) ? 'yes' : 'no',
                'identity_references' => is_array($result['migration'] ?? null) ? $result['migration'] : [],
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'personnel.flash.saved' : $result['message']);

        return Response::redirect('/module/personnel');
    }

    public function resetPassword(Request $request): Response
    {
        if (!$this->canMutate('personnel.write', $request) || !$this->canManageCredentials()) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        $profileKey = (string) $request->input('profile_key', '');
        $profile = $this->userProfiles->find($profileKey);

        if ($profile === null || !$this->canViewProfile($profile)) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        $result = $this->credentials->reset($profileKey);

        if (!empty($result['ok'])) {
            $this->auditLog->record($this->auth->user() ?? [], 'personnel.password_reset', 'personnel', $profileKey, [
                'name' => (string) ($result['name'] ?? ''),
                'username' => (string) ($result['username'] ?? ''),
                'delivery' => (string) ($result['delivery'] ?? ''),
                'mail_transport' => (string) ($result['mail_transport'] ?? ''),
                'revoked_tokens' => (string) ($result['revoked_tokens'] ?? 0),
            ]);

            if ((string) ($result['password'] ?? '') !== '') {
                Session::flash('personnel_credential', [
                    'name' => (string) ($result['name'] ?? ''),
                    'username' => (string) ($result['username'] ?? ''),
                    'password' => (string) $result['password'],
                    'delivery' => (string) ($result['delivery'] ?? 'screen'),
                ]);
            }
        }

        $flashType = !empty($result['ok']) && ($result['delivery'] ?? '') !== 'screen_fallback'
            ? 'success'
            : 'error';
        Session::flash($flashType, (string) ($result['message'] ?? 'personnel.flash.password_reset_failed'));

        return Response::redirect('/module/personnel');
    }

    public function delete(Request $request): Response
    {
        if (!$this->canMutate('personnel.delete', $request)) {
            return Response::redirect('/module/personnel');
        }

        $email = (string) $request->input('profile_key', $request->input('email'));
        $before = $this->userProfiles->find($email);
        $currentUser = $this->auth->user() ?? [];

        if ($before !== null && !$this->canViewProfile($before)) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return Response::redirect('/module/personnel');
        }

        if (($currentUser['email'] ?? '') === $email) {
            Session::flash('error', 'personnel.flash.delete_self_blocked');

            return Response::redirect('/module/personnel');
        }

        $result = $this->userProfiles->deleteProfile($email);

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'personnel.profile_deleted', 'personnel', $email, [
                'name' => (string) ($before['name'] ?? ''),
                'department' => (string) ($before['department'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/personnel');
    }

    private function canRead(): bool
    {
        return $this->auth->can('module.personnel.access') && $this->auth->can('personnel.read');
    }

    private function canExport(): bool
    {
        return $this->canRead() && $this->auth->can('personnel.export');
    }

    private function canManageCredentials(): bool
    {
        $user = $this->auth->user() ?? [];
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
        $workforceRoles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];

        return in_array('*', $permissions, true)
            || in_array('admin.company.manage', $permissions, true)
            || in_array('leave.request.manage.hr', $permissions, true)
            || in_array('hr', $workforceRoles, true)
            || in_array('hr_assistant', $workforceRoles, true);
    }

    private function canMutate(string $permission, Request $request): bool
    {
        if (!$this->auth->check()) {
            return false;
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return false;
        }

        if (!$this->canRead() || !$this->auth->can($permission)) {
            Session::flash('error', 'personnel.flash.not_allowed');

            return false;
        }

        return true;
    }

    private function sortedProfiles(): array
    {
        $profiles = array_map(function (array $profile): array {
            $profile['personnel_group'] = $this->personnelGroup($profile);

            return $profile;
        }, array_values(array_filter(
            $this->userProfiles->users(),
            fn (array $profile): bool => $this->canViewProfile($profile)
        )));

        usort($profiles, function (array $a, array $b): int {
            $groupComparison = $this->personnelGroupOrder((string) ($a['personnel_group'] ?? 'office'))
                <=> $this->personnelGroupOrder((string) ($b['personnel_group'] ?? 'office'));

            if ($groupComparison !== 0) {
                return $groupComparison;
            }

            $locationComparison = strcmp((string) ($a['location'] ?? ''), (string) ($b['location'] ?? ''));

            if ($locationComparison !== 0) {
                return $locationComparison;
            }

            $departmentComparison = strcmp((string) ($a['department'] ?? ''), (string) ($b['department'] ?? ''));

            if ($departmentComparison !== 0) {
                return $departmentComparison;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $profiles;
    }

    private function canViewProfile(array $profile): bool
    {
        $viewer = $this->auth->user() ?? [];
        $permissions = is_array($viewer['permissions'] ?? null) ? $viewer['permissions'] : [];

        if (in_array('*', $permissions, true) || in_array('admin.company.manage', $permissions, true) || in_array('leave.request.manage.hr', $permissions, true)) {
            return true;
        }

        $viewerKey = (string) ($viewer['email'] ?? '');
        $profileKey = (string) ($profile['profile_key'] ?? ($profile['email'] ?? ''));

        if ($viewerKey !== '' && $profileKey !== '' && $viewerKey === $profileKey) {
            return true;
        }

        if (!LocationScope::canView($viewer, $profile)) {
            return false;
        }

        $viewerDepartment = (string) ($viewer['department'] ?? '');
        $profileDepartment = (string) ($profile['department'] ?? '');

        if (in_array('leave.request.approve.department', $permissions, true)) {
            if ($viewerDepartment !== '' && $profileDepartment !== '' && $viewerDepartment === $profileDepartment) {
                return true;
            }

            $policy = $this->accessControl->departmentPolicy($profileDepartment);

            foreach (['manager_1_email', 'manager_2_email'] as $managerKey) {
                if ($viewerKey !== '' && (string) ($policy[$managerKey] ?? '') === $viewerKey) {
                    return true;
                }
            }
        }

        return $this->personnelGroupFromDepartment($viewerDepartment) === $this->personnelGroupFromDepartment($profileDepartment);
    }

    private function visibleDepartmentNames(): array
    {
        $departments = [];

        foreach ($this->sortedProfiles() as $profile) {
            $department = (string) ($profile['department'] ?? '');

            if ($department !== '') {
                $departments[$department] = $department;
            }
        }

        $departments = array_values($departments);
        sort($departments);

        return $departments;
    }

    private function visibleDepartmentOptions(): array
    {
        $visible = array_fill_keys($this->visibleDepartmentNames(), true);

        return array_values(array_filter(
            $this->accessControl->departmentOptions(),
            static fn (array $department): bool => isset($visible[(string) ($department['name'] ?? '')])
        ));
    }

    private function canUseDepartment(string $department): bool
    {
        $department = trim($department);

        if ($department === '') {
            return true;
        }

        $viewer = $this->auth->user() ?? [];
        $permissions = is_array($viewer['permissions'] ?? null) ? $viewer['permissions'] : [];

        if (in_array('*', $permissions, true) || in_array('admin.company.manage', $permissions, true) || in_array('leave.request.manage.hr', $permissions, true)) {
            return true;
        }

        return in_array($department, $this->visibleDepartmentNames(), true);
    }

    private function canUseLocation(string $location): bool
    {
        $viewer = $this->auth->user() ?? [];

        if (LocationScope::hasGlobalVisibility($viewer)) {
            return true;
        }

        $location = LocationScope::normalize($location);

        return $location !== '' && $location === LocationScope::locationForProfile($viewer);
    }

    private function personnelGroupCounts(array $profiles): array
    {
        $counts = [
            'all' => count($profiles),
            'office' => 0,
            'blue' => 0,
            'system' => 0,
        ];

        foreach ($profiles as $profile) {
            $group = (string) ($profile['personnel_group'] ?? 'office');

            if (!array_key_exists($group, $counts)) {
                $group = 'office';
            }

            $counts[$group]++;
        }

        return $counts;
    }

    private function personnelGroup(array $profile): string
    {
        $role = $this->normalizeGroupText((string) ($profile['role'] ?? ''));
        $department = $this->normalizeGroupText((string) ($profile['department'] ?? ''));
        $email = $this->normalizeGroupText((string) ($profile['email'] ?? ''));

        if (
            str_contains($role, 'system')
            || str_contains($role, 'admin')
            || str_contains($department, 'system')
            || str_contains($department, 'sistem')
            || str_contains($email, 'example.com')
        ) {
            return 'system';
        }

        if (
            str_contains($department, 'mavi')
            || str_contains($department, 'blue')
            || str_contains($department, 'gazileri')
            || preg_match('/(^|\\s|_)bc($|\\s|_)/', $department) === 1
        ) {
            return 'blue';
        }

        return 'office';
    }

    private function personnelGroupOrder(string $group): int
    {
        return match ($group) {
            'office' => 0,
            'blue' => 1,
            'system' => 2,
            default => 0,
        };
    }

    private function personnelGroupFromDepartment(string $department): string
    {
        return $this->personnelGroup([
            'role' => '',
            'department' => $department,
            'email' => '',
        ]);
    }

    private function normalizeGroupText(string $value): string
    {
        $value = strtr($value, [
            'İ' => 'i',
            'I' => 'i',
            'ı' => 'i',
            'Ş' => 's',
            'ş' => 's',
            'Ğ' => 'g',
            'ğ' => 'g',
            'Ü' => 'u',
            'ü' => 'u',
            'Ö' => 'o',
            'ö' => 'o',
            'Ç' => 'c',
            'ç' => 'c',
        ]);

        return strtolower(trim($value));
    }

    private function deletableEmails(): array
    {
        $emails = [];

        foreach ($this->userProfiles->users() as $email => $profile) {
            if ($this->canViewProfile($profile) && $this->userProfiles->canDeleteProfile((string) $email)) {
                $emails[$email] = true;
            }
        }

        return $emails;
    }
}
