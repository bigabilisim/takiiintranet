<?php

namespace App\Modules\Templates;

use App\Core\StateStore;

class TemplateStore
{
    private const STATE_KEY = 'templates';
    private const VERSION = 2;
    private readonly TemplateSanitizer $sanitizer;

    public function __construct(
        private readonly StateStore $stateStore,
        ?TemplateSanitizer $sanitizer = null,
    )
    {
        $this->sanitizer = $sanitizer ?? new TemplateSanitizer();
    }

    public function templates(): array
    {
        $templates = $this->data()['templates'];

        usort($templates, function (array $a, array $b): int {
            if (($a['type'] ?? '') !== ($b['type'] ?? '')) {
                return strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? ''));
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $templates;
    }

    public function find(string $id): ?array
    {
        foreach ($this->templates() as $template) {
            if (($template['id'] ?? '') === $id) {
                return $template;
            }
        }

        return null;
    }

    public function save(array $actor, array $input): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $id = trim((string) ($input['template_id'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $type = trim((string) ($input['type'] ?? 'mail'));
        $description = trim((string) ($input['description'] ?? ''));
        $html = $this->sanitizer->sanitizeHtml((string) ($input['html'] ?? ''));
        $css = $this->sanitizer->sanitizeCss((string) ($input['css'] ?? ''));

        if ($name === '' || !in_array($type, ['mail', 'report'], true)) {
            return ['ok' => false, 'message' => 'templates.flash.invalid', 'id' => $id];
        }

        if ($html === '') {
            return ['ok' => false, 'message' => 'templates.flash.empty', 'id' => $id];
        }

        $data = $this->data();
        $id = $id !== '' ? $id : $this->nextId($data['templates'], $type);
        $saved = false;

        foreach ($data['templates'] as $index => $template) {
            if (($template['id'] ?? '') !== $id) {
                continue;
            }

            $data['templates'][$index] = array_merge($template, [
                'type' => $type,
                'name' => substr($name, 0, 120),
                'description' => substr($description, 0, 240),
                'html' => $html,
                'css' => $css,
                'project_data' => null,
                'updated_at' => date('Y-m-d H:i'),
                'updated_by' => (string) ($actor['name'] ?? $actor['email'] ?? ''),
            ]);
            $saved = true;
            break;
        }

        if (!$saved) {
            $data['templates'][] = [
                'id' => $id,
                'type' => $type,
                'name' => substr($name, 0, 120),
                'description' => substr($description, 0, 240),
                'html' => $html,
                'css' => $css,
                'project_data' => null,
                'created_at' => date('Y-m-d H:i'),
                'updated_at' => date('Y-m-d H:i'),
                'updated_by' => (string) ($actor['name'] ?? $actor['email'] ?? ''),
            ];
        }

        $this->saveData($data);

        return ['ok' => true, 'message' => 'templates.flash.saved', 'id' => $id];
    }

    private function data(): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = $this->loadData();

        if (!isset($data['templates']) || !is_array($data['templates'])) {
            return $this->seedData();
        }

        $changed = ($data['version'] ?? null) !== self::VERSION;
        $data['version'] = self::VERSION;

        foreach ($data['templates'] as $index => $template) {
            if (!is_array($template)) {
                unset($data['templates'][$index]);
                $changed = true;

                continue;
            }

            $html = $this->sanitizer->sanitizeHtml((string) ($template['html'] ?? ''));
            $css = $this->sanitizer->sanitizeCss((string) ($template['css'] ?? ''));

            if ($html !== (string) ($template['html'] ?? '')
                || $css !== (string) ($template['css'] ?? '')
                || ($template['project_data'] ?? null) !== null) {
                $data['templates'][$index]['html'] = $html;
                $data['templates'][$index]['css'] = $css;
                $data['templates'][$index]['project_data'] = null;
                $changed = true;
            }
        }

        $data['templates'] = array_values($data['templates']);

        if ($changed) {
            $this->saveData($data);
        }

        return $data;
    }

    private function seedData(): array
    {
        $data = [
            'version' => self::VERSION,
            'templates' => [
                [
                    'id' => 'TPL-MAIL-1001',
                    'type' => 'mail',
                    'name' => 'Annual leave approval mail',
                    'description' => 'Base template for leave approval emails sent to managers.',
                    'html' => $this->defaultMailHtml(),
                    'css' => $this->defaultMailCss(),
                    'project_data' => null,
                    'created_at' => '2026-05-03 09:00',
                    'updated_at' => '2026-05-03 09:00',
                    'updated_by' => 'System',
                ],
                [
                    'id' => 'TPL-REPORT-1001',
                    'type' => 'report',
                    'name' => 'Monthly HR leave report',
                    'description' => 'Monthly leave summary report for HR and department managers.',
                    'html' => $this->defaultReportHtml(),
                    'css' => $this->defaultReportCss(),
                    'project_data' => null,
                    'created_at' => '2026-05-03 09:00',
                    'updated_at' => '2026-05-03 09:00',
                    'updated_by' => 'System',
                ],
            ],
        ];

        $this->saveData($data);

        return $data;
    }

    private function loadData(): array
    {
        return $this->stateStore->read(self::STATE_KEY, $this->dataPath(), $this->emptyData());
    }

    private function saveData(array $data): void
    {
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), $data);
    }

    private function emptyData(): array
    {
        return ['version' => 0, 'templates' => null];
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/templates.json';
    }

    private function nextId(array $templates, string $type): string
    {
        $prefix = $type === 'report' ? 'TPL-REPORT-' : 'TPL-MAIL-';

        return $prefix . (1001 + count($templates));
    }

    private function defaultMailHtml(): string
    {
        return <<<'HTML'
<section class="mail-shell">
  <div class="mail-header">MyTakii Intranet</div>
  <h1>Your annual leave request is waiting for approval</h1>
  <p>Hello {{manager_name}}, the leave request created by {{employee_name}} is waiting for your approval.</p>
  <div class="mail-summary">
    <strong>{{leave_dates}}</strong>
    <span>{{leave_days}} days</span>
  </div>
  <a class="mail-button" href="{{approval_link}}">Review and approve request</a>
  <p class="mail-note">This link is valid for 96 hours.</p>
</section>
HTML;
    }

    private function defaultMailCss(): string
    {
        return <<<'CSS'
.mail-shell { max-width: 640px; margin: 0 auto; padding: 32px; font-family: Arial, sans-serif; color: #1f2428; background: #ffffff; }
.mail-header { margin-bottom: 22px; color: #2f6f62; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.mail-shell h1 { margin: 0 0 14px; font-size: 28px; line-height: 1.15; }
.mail-shell p { color: #5f666d; line-height: 1.55; }
.mail-summary { display: grid; gap: 4px; margin: 22px 0; padding: 16px; border-left: 4px solid #4a68a8; background: #eef3ff; }
.mail-button { display: inline-block; padding: 13px 18px; border-radius: 8px; background: #1f2428; color: #ffffff; text-decoration: none; font-weight: 800; }
.mail-note { font-size: 12px; }
CSS;
    }

    private function defaultReportHtml(): string
    {
        return <<<'HTML'
<section class="report-shell">
  <header>
    <span>HR Report</span>
    <h1>Monthly leave summary</h1>
    <p>{{report_month}} period</p>
  </header>
  <div class="report-metrics">
    <div><span>Total requests</span><strong>{{total_requests}}</strong></div>
    <div><span>Approved</span><strong>{{approved_requests}}</strong></div>
    <div><span>Pending</span><strong>{{pending_requests}}</strong></div>
  </div>
  <table>
    <thead><tr><th>Department</th><th>Used</th><th>Pending</th></tr></thead>
    <tbody>
      <tr><td>Product</td><td>{{product_used}}</td><td>{{product_pending}}</td></tr>
      <tr><td>People</td><td>{{people_used}}</td><td>{{people_pending}}</td></tr>
    </tbody>
  </table>
</section>
HTML;
    }

    private function defaultReportCss(): string
    {
        return <<<'CSS'
.report-shell { padding: 34px; font-family: Arial, sans-serif; color: #1f2428; background: #f8faf8; }
.report-shell header { margin-bottom: 24px; }
.report-shell header span { color: #2f6f62; font-size: 12px; font-weight: 800; text-transform: uppercase; }
.report-shell h1 { margin: 6px 0; font-size: 34px; }
.report-metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
.report-metrics div { padding: 16px; border: 1px solid #d7ddd8; border-radius: 8px; background: #ffffff; }
.report-metrics span { display: block; color: #6c7076; font-size: 12px; }
.report-metrics strong { font-size: 26px; }
.report-shell table { width: 100%; border-collapse: collapse; background: #ffffff; }
.report-shell th, .report-shell td { padding: 14px; border-bottom: 1px solid #d7ddd8; text-align: left; }
CSS;
    }
}
