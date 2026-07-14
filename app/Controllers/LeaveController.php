<?php

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\AuditLogStore;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Modules\Leave\LeaveStore;
use App\Modules\Notifications\PushNotificationStore;

class LeaveController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly LeaveStore $leaveStore,
        private readonly PushNotificationStore $pushStore,
        private readonly Translator $translator,
        private readonly AccessControl $accessControl,
        private readonly AuditLogStore $auditLog,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.leave.access')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $user = $this->auth->user() ?? [];

        return new Response($this->view->render('leave/index', [
            'title' => 'module.leave.title',
            'leaveBalance' => $this->leaveStore->balanceForUser($user),
            'upcomingEntitlement' => $this->leaveStore->upcomingEntitlementForUser($user),
            'departmentPolicy' => $this->leaveStore->policyForDepartment($user['department'] ?? ''),
            'approvalQueue' => $this->leaveStore->pendingApprovalsFor($this->auth),
            'cancellationQueue' => $this->leaveStore->cancellableRequestsFor($this->auth),
            'requesterEditableRequests' => $this->leaveStore->requesterEditableRequestsFor($user),
            'requesterCancellableRequests' => $this->leaveStore->requesterCancellableRequestsFor($user),
            'requesterHistoryRequests' => $this->leaveStore->requesterHistoryRequestsFor($user),
            'calendar' => $this->leaveStore->calendar(
                (string) $request->input('view', 'month'),
                (string) $request->input('date', date('Y-m-d')),
                $user,
                $this->auth
            ),
        ]));
    }

    public function create(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.leave.access') || !$this->auth->can('leave.request.create')) {
            Session::flash('error', 'leave.flash.not_allowed');

            return Response::redirect('/module/leave');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/leave');
        }

        $result = $this->leaveStore->create($this->auth->user() ?? [], $request->all());
        $this->sendLeaveApprovalPushes($result['notifications'] ?? []);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/leave');
    }

    public function updateOwnRequest(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.leave.access')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/leave');
        }

        $before = $this->leaveStore->findById($id);
        $result = $this->leaveStore->updateByRequesterBeforeFirstApproval($id, $this->auth->user() ?? [], $request->all());

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'leave.request_updated_by_requester', 'leave_request', $id, [
                'previous_starts_on' => (string) ($before['starts_on'] ?? ''),
                'previous_ends_on' => (string) ($before['ends_on'] ?? ''),
                'previous_total_days' => (string) ($before['total_days'] ?? ''),
                'starts_on' => (string) ($result['request']['starts_on'] ?? ''),
                'ends_on' => (string) ($result['request']['ends_on'] ?? ''),
                'total_days' => (string) ($result['request']['total_days'] ?? ''),
                'notifications' => 'none',
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/leave');
    }

    public function cancelOwnRequest(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.leave.access')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/leave');
        }

        $before = $this->leaveStore->findById($id);
        $result = $this->leaveStore->cancelByRequesterBeforeFirstApproval($id, $this->auth->user() ?? []);

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'leave.request_cancelled_by_requester', 'leave_request', $id, [
                'requester' => (string) ($before['requester'] ?? ''),
                'starts_on' => (string) ($before['starts_on'] ?? ''),
                'ends_on' => (string) ($before['ends_on'] ?? ''),
                'previous_status' => (string) ($before['status'] ?? ''),
                'notifications' => 'none',
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/leave');
    }

    public function requestCancellation(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.leave.access')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/leave');
        }

        $before = $this->leaveStore->findById($id);
        $result = $this->leaveStore->requestCancellationByRequester($id, $this->auth->user() ?? []);
        $this->sendLeaveApprovalPushes($result['notifications'] ?? []);

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'leave.cancellation_requested', 'leave_request', $id, [
                'requester' => (string) ($before['requester'] ?? ''),
                'starts_on' => (string) ($before['starts_on'] ?? ''),
                'ends_on' => (string) ($before['ends_on'] ?? ''),
                'current_status' => (string) ($before['status'] ?? ''),
                'approver_email' => (string) ($result['request']['cancellation_request']['approver_email'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/leave');
    }

    public function cancel(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/leave');
        }

        $before = $this->leaveStore->findById($id);
        $result = $this->leaveStore->cancelByPlatform($id, $this->auth->user() ?? [], $this->auth);

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'leave.request_cancelled', 'leave_request', $id, [
                'requester' => (string) ($before['requester'] ?? ''),
                'starts_on' => (string) ($before['starts_on'] ?? ''),
                'ends_on' => (string) ($before['ends_on'] ?? ''),
                'previous_status' => (string) ($before['status'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/leave');
    }

    public function decision(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/leave');
        }

        $result = $this->leaveStore->advanceByPlatform(
            $id,
            $this->auth->user() ?? [],
            $this->auth,
            (string) $request->input('decision'),
            (string) $request->input('decision_note', '')
        );
        $this->sendLeaveApprovalPushes($result['notifications'] ?? []);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/leave');
    }

    public function mailDecision(Request $request, string $token, string $decision): Response
    {
        $result = $this->leaveStore->advanceByToken($token, $decision);
        $this->sendLeaveApprovalPushes($result['notifications'] ?? []);

        return new Response($this->view->render('leave/mail-result', [
            'title' => 'leave.mail_approval',
            'result' => $result,
        ]));
    }

    public function bookSignatureDecision(Request $request, string $token, string $decision): Response
    {
        $result = $this->leaveStore->markLeaveBookSignatureByToken($token, $decision);

        return new Response($this->view->render('leave/mail-result', [
            'title' => 'leave.mail_signature',
            'result' => $result,
        ]));
    }

    public function bookSignatures(Request $request): Response
    {
        if (!$this->canManageBookSignatures()) {
            return $this->auth->check()
                ? new Response($this->view->render('errors/404', ['title' => '404']), 404)
                : Response::redirect('/login');
        }

        return new Response($this->view->render('leave/book-signatures', [
            'title' => 'nav.leave_book_signatures',
            'signatureQueue' => $this->leaveStore->pendingLeaveBookSignaturesFor($this->auth),
        ]));
    }

    public function signBookSignature(Request $request, string $id): Response
    {
        if (!$this->canManageBookSignatures()) {
            Session::flash('error', 'leave.flash.not_allowed');

            return $this->auth->check() ? Response::redirect('/leave/book-signatures') : Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/leave/book-signatures');
        }

        $before = $this->leaveStore->findById($id);
        $result = $this->leaveStore->markLeaveBookSignatureByPlatform($id, $this->auth->user() ?? [], $this->auth);

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'leave.book_signature_signed', 'leave_request', $id, [
                'requester' => (string) ($before['requester'] ?? ''),
                'starts_on' => (string) ($before['starts_on'] ?? ''),
                'ends_on' => (string) ($before['ends_on'] ?? ''),
                'source' => 'platform',
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/leave/book-signatures');
    }

    public function policies(Request $request): Response
    {
        if (!$this->canManagePolicies()) {
            return $this->auth->check()
                ? new Response($this->view->render('errors/404', ['title' => '404']), 404)
                : Response::redirect('/login');
        }

        return new Response($this->view->render('leave/policies', [
            'title' => 'leave_policy.title',
            'users' => $this->accessControl->users(),
            'departmentHierarchy' => $this->accessControl->departmentHierarchy(),
            'departmentParents' => $this->accessControl->departmentParents(),
            'departmentUserCounts' => $this->accessControl->departmentUserCounts(),
            'departmentChildCounts' => $this->accessControl->departmentChildCounts(),
            'departmentPolicies' => $this->accessControl->departmentPolicies(),
        ]));
    }

    public function updatePolicy(Request $request): Response
    {
        if (!$this->canMutatePolicies($request)) {
            return Response::redirect('/leave/policies');
        }

        $department = (string) $request->input('department');
        $parents = $this->accessControl->departmentParents();

        if (($parents[$department] ?? '') === '') {
            Session::flash('error', 'leave_policy.flash.sub_department_required');

            return Response::redirect('/leave/policies');
        }

        $before = $this->accessControl->departmentPolicy($department);
        $updated = $this->accessControl->setDepartmentPolicy($department, [
            'manager_approval_count' => $request->input('manager_approval_count'),
            'manager_1_email' => $request->input('manager_1_email'),
            'manager_2_email' => $request->input('manager_2_email'),
            'hr_email' => $request->input('hr_email'),
        ]);

        if (!$updated) {
            Session::flash('error', 'admin.flash.department_not_found');

            return Response::redirect('/leave/policies');
        }

        $after = $this->accessControl->departmentPolicy($department);
        $syncResult = $this->leaveStore->syncDepartmentPolicy($department, $after);
        $this->sendLeaveApprovalPushes($syncResult['notifications'] ?? []);
        $this->auditLog->record($this->auth->user() ?? [], 'department.policy_updated', 'department', $department, [
            'source' => 'leave_policy_menu',
            'parent' => (string) ($parents[$department] ?? ''),
            'before' => $before,
            'after' => $after,
            'pending_leave_requests_synced' => (string) ($syncResult['updated'] ?? 0),
        ]);
        Session::flash('success', 'leave_policy.flash.saved');

        return Response::redirect('/leave/policies');
    }

    public function createSubDepartment(Request $request): Response
    {
        if (!$this->canMutatePolicies($request)) {
            return Response::redirect('/leave/policies');
        }

        $parent = (string) $request->input('parent_department');

        if ($parent === '') {
            Session::flash('error', 'leave_policy.flash.parent_required');

            return Response::redirect('/leave/policies');
        }

        $result = $this->accessControl->createDepartment(
            (string) $request->input('department_name'),
            $parent
        );

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'department.created', 'department', (string) ($result['department'] ?? ''), [
                'source' => 'leave_policy_menu',
                'department' => (string) ($result['department'] ?? ''),
                'parent' => (string) ($result['parent'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/leave/policies');
    }

    public function assignSubDepartment(Request $request): Response
    {
        if (!$this->canMutatePolicies($request)) {
            return Response::redirect('/leave/policies');
        }

        $department = (string) $request->input('department');
        $parent = (string) $request->input('parent_department');
        $beforeParents = $this->accessControl->departmentParents();
        $result = $this->accessControl->setDepartmentParent($department, $parent);

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'department.parent_updated', 'department', (string) ($result['department'] ?? ''), [
                'source' => 'leave_policy_menu',
                'department' => (string) ($result['department'] ?? ''),
                'before_parent' => (string) ($beforeParents[$department] ?? ''),
                'after_parent' => (string) ($result['parent'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/leave/policies');
    }

    public function deleteSubDepartment(Request $request): Response
    {
        if (!$this->canMutatePolicies($request)) {
            return Response::redirect('/leave/policies');
        }

        $department = (string) $request->input('department');
        $parents = $this->accessControl->departmentParents();

        if (($parents[$department] ?? '') === '') {
            Session::flash('error', 'leave_policy.flash.sub_department_required');

            return Response::redirect('/leave/policies');
        }

        $result = $this->accessControl->deleteDepartment($department);
        $this->auditLog->record($this->auth->user() ?? [], $result['ok'] ? 'department.deleted' : 'department.delete_blocked', 'department', $department, [
            'source' => 'leave_policy_menu',
            'department' => $department,
            'parent' => (string) ($parents[$department] ?? ''),
            'user_count' => (string) ($result['user_count'] ?? 0),
            'child_count' => (string) ($result['child_count'] ?? 0),
            'result' => $result['ok'] ? 'ok' : 'blocked',
        ]);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/leave/policies');
    }

    private function sendLeaveApprovalPushes(array $notifications): void
    {
        foreach ($notifications as $notification) {
            $toEmail = (string) ($notification['to_email'] ?? '');

            if ($toEmail === '') {
                continue;
            }

            $dates = (string) ($notification['date_range'] ?? '');

            if ($dates === '') {
                $dates = trim((string) ($notification['starts_on'] ?? '') . ' - ' . (string) ($notification['ends_on'] ?? ''));
            }

            $dayPartKey = (string) ($notification['day_part_key'] ?? 'leave.day_part.full');

            if ($dayPartKey !== 'leave.day_part.full') {
                $dates .= ' / ' . $this->translator->get($dayPartKey);
            }

            $isCancellation = (string) ($notification['type'] ?? '') === 'leave_cancellation_approval';
            $this->pushStore->sendToUser($toEmail, [
                'title' => $this->translator->get($isCancellation ? 'leave.push.cancellation_title' : 'leave.push.approval_title'),
                'body' => $this->translator->get($isCancellation ? 'leave.push.cancellation_body' : 'leave.push.approval_body', [
                    'requester' => (string) ($notification['requester'] ?? ''),
                    'dates' => $dates,
                ]),
                'url' => '/module/leave',
                'tag' => ($isCancellation ? 'leave-cancellation-' : 'leave-approval-') . hash('sha1', (string) ($notification['request_id'] ?? '') . '-' . (string) ($notification['stage'] ?? '') . '-' . $toEmail),
            ]);
        }
    }

    private function canManagePolicies(): bool
    {
        return $this->auth->check() && $this->auth->can('leave.request.manage.hr');
    }

    private function canManageBookSignatures(): bool
    {
        return $this->auth->check() && $this->auth->can('leave.request.manage.hr');
    }

    private function canMutatePolicies(Request $request): bool
    {
        if (!$this->canManagePolicies()) {
            Session::flash('error', 'leave.flash.not_allowed');

            return false;
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return false;
        }

        return true;
    }
}
