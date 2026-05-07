<?php

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\AuditLogStore;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\UserProfileStore;
use App\Core\View;

class PersonnelController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly UserProfileStore $userProfiles,
        private readonly AccessControl $accessControl,
        private readonly AuditLogStore $auditLog,
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

        return new Response($this->view->render('personnel/index', [
            'title' => 'module.personnel.title',
            'personnel' => $this->sortedProfiles(),
            'departments' => $this->accessControl->departments(),
            'canWritePersonnel' => $this->auth->can('personnel.write'),
            'canDeletePersonnel' => $this->auth->can('personnel.delete'),
            'canExportPersonnel' => $this->canExport(),
            'deletableEmails' => $this->deletableEmails(),
        ]));
    }

    public function export(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->canExport()) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $this->auditLog->record($this->auth->user() ?? [], 'personnel.exported', 'personnel', 'csv', [
            'record_count' => (string) count($this->userProfiles->users()),
        ]);

        return new Response($this->userProfiles->exportProfilesCsv(), 200, [
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

        $content = $this->userProfiles->exportProfilesXlsx();

        if ($content === '') {
            Session::flash('error', 'personnel.flash.export_failed');

            return Response::redirect('/module/personnel');
        }

        $this->auditLog->record($this->auth->user() ?? [], 'personnel.exported', 'personnel', 'xlsx', [
            'record_count' => (string) count($this->userProfiles->users()),
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

        $result = $this->userProfiles->createProfile($request->all());

        if ($result['ok']) {
            $profileKey = (string) ($result['profile_key'] ?? '');
            $profile = $this->userProfiles->find($profileKey);
            $this->auditLog->record($this->auth->user() ?? [], 'personnel.profile_created', 'personnel', $profileKey, [
                'name' => (string) ($profile['name'] ?? ($result['name'] ?? '')),
                'department' => (string) ($profile['department'] ?? ''),
                'email' => (string) ($profile['email'] ?? ($result['email'] ?? '')),
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
        $result = $this->userProfiles->updateProfile($profileKey, $request->all());

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
            ]);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'personnel.flash.saved' : $result['message']);

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
        $profiles = array_values($this->userProfiles->users());

        usort($profiles, fn (array $a, array $b): int => strcmp(
            (string) ($a['name'] ?? ''),
            (string) ($b['name'] ?? '')
        ));

        return $profiles;
    }

    private function deletableEmails(): array
    {
        $emails = [];

        foreach ($this->userProfiles->users() as $email => $profile) {
            if ($this->userProfiles->canDeleteProfile((string) $email)) {
                $emails[$email] = true;
            }
        }

        return $emails;
    }
}
