<?php

namespace App\Modules\Templates;

use App\Core\LocalizedDateFormatter;
use App\Core\StateStore;

class TemplateTestMailer
{
    private const STATE_KEY = 'template_test_mail_outbox';
    private const VERSION = 1;
    private readonly TemplateSanitizer $sanitizer;

    public function __construct(
        private readonly StateStore $stateStore,
        ?TemplateSanitizer $sanitizer = null,
        private readonly ?LocalizedDateFormatter $localizedDates = null,
    )
    {
        $this->sanitizer = $sanitizer ?? new TemplateSanitizer();
    }

    public function lastRecipientForUser(string $email): string
    {
        $email = trim($email);

        if ($email === '') {
            return '';
        }

        $decoded = $this->loadOutbox();
        $messages = is_array($decoded['messages'] ?? null) ? array_reverse($decoded['messages']) : [];

        foreach ($messages as $message) {
            if (($message['created_by_email'] ?? '') !== $email) {
                continue;
            }

            if (!in_array((string) ($message['status'] ?? ''), ['queued', 'sent'], true)) {
                continue;
            }

            $recipient = (string) ($message['to_email'] ?? '');

            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                return $recipient;
            }
        }

        return '';
    }

    public function send(array $actor, array $input): array
    {
        $toEmail = trim((string) ($input['to_email'] ?? ''));
        $templateId = trim((string) ($input['template_id'] ?? ''));
        $templateName = trim((string) ($input['template_name'] ?? ''));
        $templateType = trim((string) ($input['type'] ?? 'mail'));
        $subject = $this->cleanHeader((string) ($input['subject'] ?? ''));
        $html = $this->sanitizer->sanitizeHtml((string) ($input['html'] ?? ''));
        $css = $this->sanitizer->sanitizeCss((string) ($input['css'] ?? ''));

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'templates.flash.test_mail_invalid_recipient'];
        }

        if (!in_array($templateType, ['mail', 'report'], true)) {
            return ['ok' => false, 'message' => 'templates.flash.invalid'];
        }

        if ($html === '') {
            return ['ok' => false, 'message' => 'templates.flash.empty'];
        }

        $templateName = $templateName !== '' ? substr($templateName, 0, 120) : $templateId;
        $subject = $subject !== '' ? substr($subject, 0, 140) : $this->defaultSubject($templateName, $templateType);
        $renderedHtml = $this->wrapHtml($this->renderTokens($html, $templateType), $this->renderTokens($css, $templateType));
        $textSource = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $renderedHtml) ?: $renderedHtml;
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</div>', '</tr>'], "\n", $textSource)), ENT_QUOTES, 'UTF-8'));
        $transport = strtolower((string) (getenv('TEMPLATE_TEST_MAIL_TRANSPORT') ?: getenv('MAIL_TRANSPORT') ?: 'outbox'));
        $transport = $transport === 'native' ? 'native' : 'outbox';
        $entry = [
            'id' => 'TMAIL-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            'template_id' => $templateId,
            'template_name' => $templateName,
            'template_type' => $templateType,
            'to_email' => $toEmail,
            'subject' => $subject,
            'html' => $renderedHtml,
            'text' => $textBody,
            'status' => 'queued',
            'transport' => $transport,
            'created_at' => date('Y-m-d H:i'),
            'created_by_email' => (string) ($actor['email'] ?? ''),
            'created_by_name' => (string) ($actor['name'] ?? ($actor['email'] ?? '')),
        ];

        if ($transport === 'native') {
            $sent = $this->sendNative($toEmail, $subject, $renderedHtml);
            $entry['status'] = $sent ? 'sent' : 'failed';
            $this->appendOutbox($entry);

            return [
                'ok' => $sent,
                'message' => $sent ? 'templates.flash.test_mail_sent' : 'templates.flash.test_mail_failed',
            ];
        }

        $this->appendOutbox($entry);

        return ['ok' => true, 'message' => 'templates.flash.test_mail_queued'];
    }

    private function sendNative(string $toEmail, string $subject, string $html): bool
    {
        if (!function_exists('mail')) {
            return false;
        }

        $fromAddress = $this->cleanHeader((string) (getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@mytakii.local'));
        $fromName = $this->cleanHeader((string) (getenv('MAIL_FROM_NAME') ?: 'MyTakii Intranet'));
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'X-MyTakii-Template-Test: true',
        ];

        return mail($toEmail, $subject, $html, implode("\r\n", $headers));
    }

    private function appendOutbox(array $entry): void
    {
        $path = $this->outboxPath();
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $path, $this->emptyOutbox());
        $decoded = $this->loadOutbox();
        $outbox = is_array($decoded) && is_array($decoded['messages'] ?? null)
            ? $decoded
            : $this->emptyOutbox();
        $outbox['version'] = self::VERSION;
        $outbox['messages'][] = $entry;

        $this->stateStore->write(self::STATE_KEY, $path, $outbox);
    }

    private function loadOutbox(): array
    {
        return $this->stateStore->read(self::STATE_KEY, $this->outboxPath(), $this->emptyOutbox());
    }

    private function emptyOutbox(): array
    {
        return ['version' => self::VERSION, 'messages' => []];
    }

    private function wrapHtml(string $html, string $css): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $html . '</body></html>';
    }

    private function renderTokens(string $content, string $templateType): string
    {
        return strtr($content, $this->sampleTokens($templateType));
    }

    private function sampleTokens(string $templateType): array
    {
        $common = [
            '{{company_name}}' => 'MyTakii Intranet',
            '{{current_date}}' => date('Y-m-d'),
            '{{generated_at}}' => date('Y-m-d H:i'),
        ];

        if ($templateType === 'report') {
            return array_merge($common, [
                '{{report_month}}' => $this->localizedDates?->format(date('Y-m-01'), 'month_year') ?? date('F Y'),
                '{{total_requests}}' => '18',
                '{{approved_requests}}' => '12',
                '{{pending_requests}}' => '4',
                '{{product_used}}' => '24 gün',
                '{{product_pending}}' => '6 gün',
                '{{people_used}}' => '11 gün',
                '{{people_pending}}' => '2 gün',
            ]);
        }

        return array_merge($common, [
            '{{manager_name}}' => 'Bilal Bozduman',
            '{{employee_name}}' => 'Erdi Öz',
            '{{requester_department}}' => 'Product',
            '{{leave_dates}}' => date('Y-m-d', strtotime('+14 days')) . ' - ' . date('Y-m-d', strtotime('+18 days')),
            '{{leave_days}}' => '5',
            '{{approval_link}}' => 'https://intranet.example.com/leave/mail-approval/test-token/approve',
            '{{reject_link}}' => 'https://intranet.example.com/leave/mail-approval/test-token/reject',
        ]);
    }

    private function defaultSubject(string $templateName, string $templateType): string
    {
        $prefix = $templateType === 'report' ? 'Test raporu' : 'Test e-postası';

        return $templateName !== '' ? $prefix . ': ' . $templateName : $prefix;
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private function outboxPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/template-test-mail-outbox.json';
    }
}
