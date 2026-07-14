<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Leave\LeaveStore;
use App\Modules\Weather\WeatherStore;

class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly LeaveStore $leaveStore,
        private readonly WeatherStore $weatherStore,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        $showAnnouncementsPanel = false;
        $canViewAnnouncements = $showAnnouncementsPanel && $this->auth->can('module.announcements.access');
        $canViewLeaveCalendar = $this->auth->can('module.leave.access');

        return new Response($this->view->render('dashboard/index', [
            'title' => 'nav.dashboard',
            'worldClocks' => $this->worldClocks(),
            'newsItems' => $canViewAnnouncements ? $this->newsItems() : [],
            'leaveCalendar' => $canViewLeaveCalendar ? $this->leaveStore->calendar('month', date('Y-m-d'), $this->auth->user() ?? [], $this->auth) : null,
            'canViewAnnouncements' => $canViewAnnouncements,
            'canViewLeaveCalendar' => $canViewLeaveCalendar,
            'weeklyWeather' => $this->weatherStore->weekly(),
        ]));
    }

    private function newsItems(): array
    {
        return [
            [
                'type_key' => 'dashboard.news.type.announcement',
                'title_key' => 'dashboard.news.global_policy.title',
                'summary_key' => 'dashboard.news.global_policy.summary',
                'date' => '2026-05-03',
                'audience_key' => 'dashboard.news.audience.all',
            ],
            [
                'type_key' => 'dashboard.news.type.news',
                'title_key' => 'dashboard.news.berlin_office.title',
                'summary_key' => 'dashboard.news.berlin_office.summary',
                'date' => '2026-05-02',
                'audience_key' => 'dashboard.news.audience.eu',
            ],
            [
                'type_key' => 'dashboard.news.type.announcement',
                'title_key' => 'dashboard.news.leave_deadline.title',
                'summary_key' => 'dashboard.news.leave_deadline.summary',
                'date' => '2026-05-01',
                'audience_key' => 'dashboard.news.audience.hr',
            ],
        ];
    }

    private function worldClocks(): array
    {
        $now = new \DateTimeImmutable('now');

        return [
            [
                'label_key' => 'clock.antalya',
                'time' => $now->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('H:i'),
            ],
            [
                'label_key' => 'clock.kyoto',
                'time' => $now->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('H:i'),
            ],
            [
                'label_key' => 'clock.netherlands',
                'time' => $now->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('H:i'),
            ],
        ];
    }

}
