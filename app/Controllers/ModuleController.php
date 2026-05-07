<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

class ModuleController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly array $modules,
    ) {
    }

    public function show(Request $request, string $slug): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        $module = $this->findModule($slug);

        if ($module === null || !$this->auth->can($module['permission'])) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        return new Response($this->view->render('modules/placeholder', [
            'title' => $module['title_key'],
            'module' => $module,
            'records' => $this->recordsFor($slug),
        ]));
    }

    private function findModule(string $slug): ?array
    {
        foreach ($this->modules as $module) {
            if ($module['slug'] === $slug) {
                return $module;
            }
        }

        return null;
    }

    private function recordsFor(string $slug): array
    {
        return match ($slug) {
            'leave' => [
                ['id' => 'LV-1042', 'owner' => 'Erdi Oz', 'amount' => '5 days', 'state_key' => 'status.waiting_manager'],
                ['id' => 'LV-1043', 'owner' => 'HR User', 'amount' => '2 days', 'state_key' => 'status.approved'],
            ],
            'budget' => [
                ['id' => 'BG-2026-PROD', 'owner' => 'Product', 'amount' => '$420,000', 'state_key' => 'status.active'],
                ['id' => 'BG-2026-HR', 'owner' => 'People', 'amount' => '$180,000', 'state_key' => 'status.active'],
            ],
            'procurement' => [
                ['id' => 'PR-771', 'owner' => 'Product', 'amount' => '$12,800', 'state_key' => 'status.waiting_finance'],
                ['id' => 'PR-772', 'owner' => 'Operations', 'amount' => '$3,450', 'state_key' => 'status.draft'],
            ],
            default => [
                ['id' => strtoupper(substr($slug, 0, 2)) . '-001', 'owner' => 'Global HQ', 'amount' => 'Live', 'state_key' => 'status.active'],
                ['id' => strtoupper(substr($slug, 0, 2)) . '-002', 'owner' => 'Takii Office', 'amount' => 'Review', 'state_key' => 'status.waiting_manager'],
            ],
        };
    }
}
