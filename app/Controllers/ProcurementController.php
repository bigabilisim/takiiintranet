<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Procurement\ProcurementStore;

class ProcurementController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly ProcurementStore $procurementStore,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.procurement.access')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $requests = array_map(function (array $procurementRequest): array {
            $procurementRequest['amount_label'] = $this->procurementStore->formatAmount($procurementRequest);
            $procurementRequest['status_key'] = 'procurement.status.' . $procurementRequest['status'];

            return $procurementRequest;
        }, $this->procurementStore->all());

        return new Response($this->view->render('procurement/index', [
            'title' => 'module.procurement.title',
            'requests' => $requests,
            'canCreate' => $this->auth->can('procurement.request.create'),
        ]));
    }

    public function create(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/procurement');
        }

        if (!$this->auth->can('module.procurement.access') || !$this->auth->can('procurement.request.create')) {
            Session::flash('error', 'procurement.flash.not_allowed');

            return Response::redirect('/module/procurement');
        }

        $result = $this->procurementStore->create($this->auth->user() ?? [], $request->all());
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/procurement');
    }
}
