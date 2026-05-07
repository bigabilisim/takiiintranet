<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Translator;
use App\Modules\Notifications\PushNotificationStore;

class PushController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly PushNotificationStore $pushStore,
        private readonly Translator $translator,
    ) {
    }

    public function config(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['ok' => false], 401);
        }

        return Response::json([
            'ok' => true,
            'publicKey' => $this->pushStore->publicKey(),
        ]);
    }

    public function subscribe(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            return Response::json(['ok' => false, 'message' => 'security.invalid_session'], 403);
        }

        $result = $this->pushStore->subscribe((string) ($this->auth->user()['email'] ?? ''), $request->all());

        return Response::json($this->translateResult($result), $result['ok'] ? 200 : 422);
    }

    public function unsubscribe(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            return Response::json(['ok' => false, 'message' => 'security.invalid_session'], 403);
        }

        return Response::json($this->translateResult($this->pushStore->unsubscribe((string) ($this->auth->user()['email'] ?? ''), $request->all())));
    }

    public function test(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            return Response::json(['ok' => false, 'message' => 'security.invalid_session'], 403);
        }

        $result = $this->pushStore->sendToUser((string) ($this->auth->user()['email'] ?? ''), [
            'title' => $this->translator->get('push.test.title'),
            'body' => $this->translator->get('push.test.body'),
            'url' => '/',
            'tag' => 'kanso-test-push',
        ]);

        return Response::json($this->translateResult($result));
    }

    private function isAllowed(Request $request): bool
    {
        if (!$this->auth->check()) {
            return false;
        }

        return Csrf::validate((string) $request->header('X-CSRF-TOKEN', ''));
    }

    private function translateResult(array $result): array
    {
        if (isset($result['message'])) {
            $result['message'] = $this->translator->get((string) $result['message']);
        }

        return $result;
    }
}
