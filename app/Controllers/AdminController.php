<?php

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\AuditLogStore;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\ReleaseNoteStore;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\UserIdentityMigrationService;
use App\Core\UserProfileStore;
use App\Core\View;
use App\Modules\Leave\LeaveStore;
use App\Modules\Notifications\PushNotificationStore;

class AdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly AccessControl $accessControl,
        private readonly UserProfileStore $userProfiles,
        private readonly ReleaseNoteStore $releaseNotes,
        private readonly AuditLogStore $auditLog,
        private readonly LeaveStore $leaveStore,
        private readonly PushNotificationStore $pushStore,
        private readonly Translator $translator,
        private readonly UserIdentityMigrationService $identityMigration,
    ) {
    }

    public function access(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('admin.company.manage')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        return new Response($this->view->render('admin/access', [
            'title' => 'nav.access',
            'users' => $this->accessControl->users(),
            'permissionCatalog' => $this->accessControl->permissionCatalog(),
            'departments' => $this->accessControl->departments(),
            'departmentOptions' => $this->accessControl->departmentOptions(),
            'departmentHierarchy' => $this->accessControl->departmentHierarchy(),
            'departmentParents' => $this->accessControl->departmentParents(),
            'departmentChildCounts' => $this->accessControl->departmentChildCounts(),
            'departmentUserCounts' => $this->accessControl->departmentUserCounts(),
            'departmentPolicies' => $this->accessControl->departmentPolicies(),
            'auditLogs' => $this->auditLog->recent(12),
        ]));
    }

    public function versions(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('admin.company.manage')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        return new Response($this->view->render('admin/versions', [
            'title' => 'nav.versions',
            'releases' => $this->releaseNotes->all(),
            'mailDigest' => $this->releaseNotes->mailDigest(6),
            'mailRecipients' => $this->releaseNotes->mailRecipients(),
        ]));
    }

    public function updateUser(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return Response::redirect('/login');
        }

        $email = (string) $request->input('email');
        $permissions = $request->input('permissions', []);
        $permissions = is_array($permissions) ? $permissions : [];

        $profileResult = $this->identityMigration->updateProfile($email, $request->all());

        if (!$profileResult['ok']) {
            Session::flash('error', $profileResult['message']);

            return Response::redirect('/admin/access');
        }

        $updatedIdentity = (string) ($profileResult['profile_key'] ?? $email);
        $this->accessControl->setUserPermissions($updatedIdentity, $permissions);
        Session::flash('success', 'admin.flash.user_profile_saved');

        return Response::redirect('/admin/access');
    }

    public function updateDepartment(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return Response::redirect('/login');
        }

        $department = (string) $request->input('department');
        $before = $this->accessControl->departmentPolicy($department);
        $updated = $this->accessControl->setDepartmentPolicy($department, [
            'manager_approval_count' => $request->input('manager_approval_count'),
            'manager_1_email' => $request->input('manager_1_email'),
            'manager_2_email' => $request->input('manager_2_email'),
            'hr_email' => $request->input('hr_email'),
        ]);

        if (!$updated) {
            Session::flash('error', 'admin.flash.department_not_found');

            return Response::redirect('/admin/access');
        }

        $after = $this->accessControl->departmentPolicy($department);
        $syncResult = $this->leaveStore->syncDepartmentPolicy($department, $after);
        $this->sendLeaveApprovalPushes($syncResult['notifications'] ?? []);
        $this->auditLog->record($this->auth->user() ?? [], 'department.policy_updated', 'department', $department, [
            'before' => $before,
            'after' => $after,
            'pending_leave_requests_synced' => (string) ($syncResult['updated'] ?? 0),
        ]);
        Session::flash('success', 'admin.flash.department_policy_saved');

        return Response::redirect('/admin/access');
    }

    public function createDepartment(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return Response::redirect('/login');
        }

        $result = $this->accessControl->createDepartment(
            (string) $request->input('department_name'),
            (string) $request->input('parent_department')
        );

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'department.created', 'department', (string) ($result['department'] ?? ''), [
                'department' => (string) ($result['department'] ?? ''),
                'parent' => (string) ($result['parent'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/admin/access');
    }

    public function deleteDepartment(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return Response::redirect('/login');
        }

        $result = $this->accessControl->deleteDepartment((string) $request->input('department'));
        $department = (string) ($result['department'] ?? $request->input('department', ''));

        $this->auditLog->record($this->auth->user() ?? [], $result['ok'] ? 'department.deleted' : 'department.delete_blocked', 'department', $department, [
            'department' => $department,
            'user_count' => (string) ($result['user_count'] ?? 0),
            'child_count' => (string) ($result['child_count'] ?? 0),
            'result' => $result['ok'] ? 'ok' : 'blocked',
        ]);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/admin/access');
    }

    public function exportUsers(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('admin.company.manage')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        return new Response($this->userProfiles->exportProfilesCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="personnel-export-' . date('Ymd-His') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function importUsers(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return Response::redirect('/login');
        }

        $file = $_FILES['personnel_file'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Session::flash('error', 'admin.flash.personnel_file_missing');

            return Response::redirect('/admin/access');
        }

        $result = $this->userProfiles->importProfilesCsv((string) ($file['tmp_name'] ?? ''));
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/admin/access');
    }

    private function canManage(Request $request): bool
    {
        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return false;
        }

        if (!$this->auth->check() || !$this->auth->can('admin.company.manage')) {
            Session::flash('error', 'admin.flash.not_allowed');

            return false;
        }

        return true;
    }

    private function sendLeaveApprovalPushes(array $notifications): void
    {
        foreach ($notifications as $notification) {
            $toEmail = (string) ($notification['to_email'] ?? '');

            if ($toEmail === '') {
                continue;
            }

            $dates = trim((string) ($notification['starts_on'] ?? '') . ' - ' . (string) ($notification['ends_on'] ?? ''));
            $this->pushStore->sendToUser($toEmail, [
                'title' => $this->translator->get('leave.push.approval_title'),
                'body' => $this->translator->get('leave.push.approval_body', [
                    'requester' => (string) ($notification['requester'] ?? ''),
                    'dates' => $dates,
                ]),
                'url' => '/module/leave',
                'tag' => 'leave-approval-' . hash('sha1', (string) ($notification['request_id'] ?? '') . '-' . (string) ($notification['stage'] ?? '') . '-' . $toEmail),
            ]);
        }
    }
}
