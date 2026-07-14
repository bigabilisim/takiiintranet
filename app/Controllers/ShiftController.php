<?php

namespace App\Controllers;

use App\Core\AuditLogStore;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Shift\ShiftStore;

class ShiftController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly ShiftStore $shiftStore,
        private readonly AuditLogStore $auditLog,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->canAccess()) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $user = $this->auth->user() ?? [];

        return new Response($this->view->render('shift/index', [
            'title' => 'module.shift.title',
            'templates' => $this->shiftStore->templates(),
            'enabledTemplates' => $this->shiftStore->enabledTemplates(),
            'personnel' => $this->shiftStore->personnel($user),
            'weekendDutyPersonnel' => $this->shiftStore->weekendDutyPersonnel($user),
            'weekendPlans' => $this->shiftStore->weekendPlans($user),
            'holidays' => $this->shiftStore->holidays(),
            'dayLabels' => $this->shiftStore->dayLabels(),
            'canManageShift' => $this->auth->can('shift.manage'),
        ]));
    }

    public function saveTemplate(Request $request): Response
    {
        if (!$this->canMutate($request)) {
            return Response::redirect('/module/shift');
        }

        $result = $this->shiftStore->saveTemplate($request->all());

        if ($result['ok']) {
            $template = is_array($result['template'] ?? null) ? $result['template'] : [];
            $this->auditLog->record($this->auth->user() ?? [], 'shift.template_saved', 'shift', (string) ($template['key'] ?? ''), [
                'name' => (string) ($template['name'] ?? ''),
                'starts_at' => (string) ($template['starts_at'] ?? ''),
                'ends_at' => (string) ($template['ends_at'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/shift');
    }

    public function deleteTemplate(Request $request): Response
    {
        if (!$this->canMutate($request)) {
            return Response::redirect('/module/shift');
        }

        $shiftKey = (string) $request->input('shift_key', '');
        $result = $this->shiftStore->deleteTemplate($shiftKey);

        $this->auditLog->record(
            $this->auth->user() ?? [],
            $result['ok'] ? 'shift.template_deleted' : 'shift.template_delete_blocked',
            'shift',
            $shiftKey,
            ['assigned_count' => (string) ($result['assigned_count'] ?? 0)]
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/shift');
    }

    public function saveWeekendPlan(Request $request): Response
    {
        if (!$this->canMutate($request)) {
            return Response::redirect('/module/shift');
        }

        $result = $this->shiftStore->saveWeekendPlan($request->all(), $this->auth->user() ?? []);

        if ($result['ok']) {
            $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
            $this->auditLog->record($this->auth->user() ?? [], 'shift.weekend_plan_saved', 'shift', (string) ($plan['key'] ?? ''), [
                'month' => (string) ($plan['month'] ?? ''),
                'profile_key' => (string) ($plan['profile_key'] ?? ''),
                'shift_key' => (string) ($plan['shift_key'] ?? ''),
                'working_dates' => implode(',', is_array($plan['working_dates'] ?? null) ? $plan['working_dates'] : []),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/shift');
    }

    public function saveHoliday(Request $request): Response
    {
        if (!$this->canMutate($request)) {
            return Response::redirect('/module/shift');
        }

        $result = $this->shiftStore->saveHoliday($request->all());

        if ($result['ok']) {
            $holiday = is_array($result['holiday'] ?? null) ? $result['holiday'] : [];
            $this->auditLog->record($this->auth->user() ?? [], 'shift.holiday_saved', 'shift_holiday', (string) ($holiday['date'] ?? ''), [
                'name' => (string) ($holiday['name'] ?? ''),
                'day_part' => (string) ($holiday['day_part'] ?? ''),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/shift');
    }

    public function deleteHoliday(Request $request): Response
    {
        if (!$this->canMutate($request)) {
            return Response::redirect('/module/shift');
        }

        $date = (string) $request->input('date', '');
        $result = $this->shiftStore->deleteHoliday($date);
        $this->auditLog->record(
            $this->auth->user() ?? [],
            $result['ok'] ? 'shift.holiday_deleted' : 'shift.holiday_delete_failed',
            'shift_holiday',
            $date
        );
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/shift');
    }

    public function deleteWeekendPlan(Request $request): Response
    {
        if (!$this->canMutate($request)) {
            return Response::redirect('/module/shift');
        }

        $key = (string) $request->input('plan_key', '');
        $result = $this->shiftStore->deleteWeekendPlan($key, $this->auth->user() ?? []);

        $this->auditLog->record(
            $this->auth->user() ?? [],
            $result['ok'] ? 'shift.weekend_plan_deleted' : 'shift.weekend_plan_delete_failed',
            'shift',
            $key
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/shift');
    }

    public function assign(Request $request): Response
    {
        if (!$this->canMutate($request)) {
            return Response::redirect('/module/shift');
        }

        $result = $this->shiftStore->assignToProfiles(
            (string) $request->input('shift_key', ''),
            (array) $request->input('profile_keys', []),
            $request->input('all_personnel') === '1',
            $this->auth->user() ?? []
        );

        if ($result['ok']) {
            $this->auditLog->record($this->auth->user() ?? [], 'shift.assigned', 'shift', (string) ($result['shift_key'] ?? ''), [
                'updated' => (string) ($result['updated'] ?? 0),
                'skipped' => (string) ($result['skipped'] ?? 0),
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/shift');
    }

    private function canAccess(): bool
    {
        return $this->auth->can('module.shift.access');
    }

    private function canMutate(Request $request): bool
    {
        if (!$this->auth->check()) {
            return false;
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return false;
        }

        if (!$this->canAccess() || !$this->auth->can('shift.manage')) {
            Session::flash('error', 'shift.flash.not_allowed');

            return false;
        }

        return true;
    }
}
