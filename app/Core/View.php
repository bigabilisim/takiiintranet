<?php

namespace App\Core;

use App\Modules\Leave\LeaveStore;
use App\Modules\Messaging\MessageStore;

class View
{
    public function __construct(
        private readonly string $root,
        private readonly Translator $translator,
        private readonly Auth $auth,
        private readonly array $appConfig,
        private readonly array $modules,
        private readonly ?MessageStore $messageStore = null,
        private readonly ?LeaveStore $leaveStore = null,
    ) {
    }

    public function render(string $template, array $data = [], bool $withLayout = true): string
    {
        $content = $this->renderPartial($template, $data);

        if (!$withLayout) {
            return $content;
        }

        return $this->renderPartial('layout', array_merge($data, [
            'content' => $content,
        ]));
    }

    private function renderPartial(string $template, array $data = []): string
    {
        $file = $this->root . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        $t = fn (string $key, array $replace = []): string => $this->translator->get($key, $replace);
        $localizedDates = new LocalizedDateFormatter($this->translator);
        $formatDate = fn (mixed $date, string $pattern = 'day_month_year'): string => $localizedDates->format((string) $date, $pattern);
        $calendarTitle = fn (array $calendar): string => $localizedDates->calendarTitle(
            (string) ($calendar['view'] ?? 'month'),
            (string) ($calendar['focus'] ?? date('Y-m-d')),
            (string) ($calendar['days'][0]['date'] ?? $calendar['focus'] ?? date('Y-m-d')),
            (string) ($calendar['days'][array_key_last($calendar['days'] ?? [])]['date'] ?? $calendar['focus'] ?? date('Y-m-d')),
        );
        $weekdayShort = fn (mixed $date): string => $localizedDates->weekdayShort((string) $date);
        $csrf = fn (): string => Csrf::field();
        $asset = function (string $path): string {
            $relativePath = ltrim($path, '/');
            $assetPath = '/assets/' . $relativePath;
            $filePath = dirname($this->root, 2) . '/public/assets/' . $relativePath;

            return is_file($filePath) ? $assetPath . '?v=' . filemtime($filePath) : $assetPath;
        };
        $currentLocale = $this->translator->locale();
        $availableLocales = $this->appConfig['available_locales'];
        $appName = $this->appConfig['name'];
        $user = $this->auth->user();
        $auth = $this->auth;
        $modules = $this->modules;
        $moduleBadges = $this->moduleBadges($user);
        $leaveBookSignatureAlerts = $this->leaveBookSignatureAlerts($user);
        $flashError = Session::pullFlash('error');
        $flashSuccess = Session::pullFlash('success');

        extract($data, EXTR_SKIP);

        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    private function moduleBadges(?array $user): array
    {
        if ($user === null || $this->messageStore === null || !$this->auth->can('module.messages.access')) {
            return [];
        }

        $unreadMessages = $this->messageStore->unreadCount((string) ($user['email'] ?? ''));

        return [
            'messages' => $unreadMessages,
        ];
    }

    private function leaveBookSignatureAlerts(?array $user): array
    {
        if ($user === null || $this->leaveStore === null || !$this->auth->can('module.leave.access')) {
            return [];
        }

        return $this->leaveStore->pendingLeaveBookSignatureAlertsForUser($user);
    }
}
