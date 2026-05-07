<?php

namespace App\Controllers;

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
            'calendar' => $this->leaveStore->calendar(
                (string) $request->input('view', 'month'),
                (string) $request->input('date', date('Y-m-d')),
                $user
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
            (string) $request->input('decision')
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
