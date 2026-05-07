<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Modules\Templates\TemplateStore;
use App\Modules\Templates\TemplateTestMailer;

class TemplatesController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly TemplateStore $templateStore,
        private readonly TemplateTestMailer $templateTestMailer,
        private readonly Translator $translator,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.templates.access')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $templates = $this->localizeTemplates($this->templateStore->templates());
        $selectedId = (string) $request->input('template', '');
        $selectedTemplate = $selectedId !== '' ? $this->localizeTemplate($this->templateStore->find($selectedId)) : null;

        if ($selectedTemplate === null) {
            $selectedTemplate = $templates[0] ?? null;
        }

        $user = $this->auth->user() ?? [];

        return new Response($this->view->render('templates/index', [
            'title' => 'module.templates.title',
            'grapesjsAssets' => true,
            'reportExporterAssets' => true,
            'templates' => $templates,
            'selectedTemplate' => $selectedTemplate,
            'canManageTemplates' => $this->auth->can('templates.manage'),
            'lastTestMailRecipient' => $this->templateTestMailer->lastRecipientForUser((string) ($user['email'] ?? '')),
        ]));
    }

    public function save(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/templates');
        }

        if (!$this->auth->can('module.templates.access') || !$this->auth->can('templates.manage')) {
            Session::flash('error', 'templates.flash.not_allowed');

            return Response::redirect('/module/templates');
        }

        $result = $this->templateStore->save($this->auth->user() ?? [], $request->all());

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/templates' . (!empty($result['id']) ? '?template=' . urlencode((string) $result['id']) : ''));
    }

    public function testMail(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        $templateId = (string) $request->input('template_id', '');
        $redirect = '/module/templates' . ($templateId !== '' ? '?template=' . urlencode($templateId) : '');

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect($redirect);
        }

        if (!$this->auth->can('module.templates.access') || !$this->auth->can('templates.manage')) {
            Session::flash('error', 'templates.flash.not_allowed');

            return Response::redirect($redirect);
        }

        $result = $this->templateTestMailer->send($this->auth->user() ?? [], $request->all());

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect($redirect);
    }

    private function localizeTemplates(array $templates): array
    {
        return array_map(fn (array $template): array => $this->localizeTemplate($template) ?? $template, $templates);
    }

    private function localizeTemplate(?array $template): ?array
    {
        if ($template === null || !$this->isUntouchedDefaultTemplate($template)) {
            return $template;
        }

        if (($template['id'] ?? '') === 'TPL-MAIL-1001') {
            $template['name'] = $this->translator->get('templates.default.mail.name');
            $template['description'] = $this->translator->get('templates.default.mail.description');
            $template['html'] = $this->defaultMailHtml();
        }

        if (($template['id'] ?? '') === 'TPL-REPORT-1001') {
            $template['name'] = $this->translator->get('templates.default.report.name');
            $template['description'] = $this->translator->get('templates.default.report.description');
            $template['html'] = $this->defaultReportHtml();
        }

        return $template;
    }

    private function isUntouchedDefaultTemplate(array $template): bool
    {
        return in_array((string) ($template['id'] ?? ''), ['TPL-MAIL-1001', 'TPL-REPORT-1001'], true)
            && (string) ($template['updated_by'] ?? '') === 'System'
            && ($template['project_data'] ?? null) === null;
    }

    private function defaultMailHtml(): string
    {
        $title = $this->html($this->translator->get('templates.default.mail.title'));
        $body = $this->html($this->translator->get('templates.default.mail.body'));
        $days = $this->html($this->translator->get('templates.default.mail.days'));
        $button = $this->html($this->translator->get('templates.default.mail.button'));
        $note = $this->html($this->translator->get('templates.default.mail.note'));

        return <<<HTML
<section class="mail-shell">
  <div class="mail-header">Kanso Intranet</div>
  <h1>{$title}</h1>
  <p>{$body}</p>
  <div class="mail-summary">
    <strong>{{leave_dates}}</strong>
    <span>{{leave_days}} {$days}</span>
  </div>
  <a class="mail-button" href="{{approval_link}}">{$button}</a>
  <p class="mail-note">{$note}</p>
</section>
HTML;
    }

    private function defaultReportHtml(): string
    {
        $kicker = $this->html($this->translator->get('templates.default.report.kicker'));
        $title = $this->html($this->translator->get('templates.default.report.title'));
        $period = $this->html($this->translator->get('templates.default.report.period'));
        $total = $this->html($this->translator->get('templates.default.report.total'));
        $approved = $this->html($this->translator->get('templates.default.report.approved'));
        $pending = $this->html($this->translator->get('templates.default.report.pending'));
        $department = $this->html($this->translator->get('templates.default.report.department'));
        $used = $this->html($this->translator->get('templates.default.report.used'));

        return <<<HTML
<section class="report-shell">
  <header>
    <span>{$kicker}</span>
    <h1>{$title}</h1>
    <p>{$period}</p>
  </header>
  <div class="report-metrics">
    <div><span>{$total}</span><strong>{{total_requests}}</strong></div>
    <div><span>{$approved}</span><strong>{{approved_requests}}</strong></div>
    <div><span>{$pending}</span><strong>{{pending_requests}}</strong></div>
  </div>
  <table>
    <thead><tr><th>{$department}</th><th>{$used}</th><th>{$pending}</th></tr></thead>
    <tbody>
      <tr><td>Product</td><td>{{product_used}}</td><td>{{product_pending}}</td></tr>
      <tr><td>People</td><td>{{people_used}}</td><td>{{people_pending}}</td></tr>
    </tbody>
  </table>
</section>
HTML;
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
