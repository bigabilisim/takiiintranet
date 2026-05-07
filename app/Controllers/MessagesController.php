<?php

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Modules\Messaging\MessageStore;
use App\Modules\Notifications\PushNotificationStore;

class MessagesController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly MessageStore $messageStore,
        private readonly AccessControl $accessControl,
        private readonly Translator $translator,
        private readonly PushNotificationStore $pushStore,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!$this->auth->can('module.messages.access')) {
            return new Response($this->view->render('errors/404', ['title' => '404']), 404);
        }

        $user = $this->auth->user() ?? [];
        $email = (string) ($user['email'] ?? '');
        $selectedRecipient = (string) ($request->input('thread') ?: $request->input('to', ''));
        $conversations = $this->messageStore->conversations($email);
        $quickContacts = $this->messageStore->quickContacts($email, $this->departmentManagerEmail($user));
        $canManageDeletedMessages = $this->auth->can('admin.company.manage');

        if ($selectedRecipient === $email) {
            $selectedRecipient = '';
        }

        if ($selectedRecipient !== '' && $this->messageStore->contact($selectedRecipient) === null) {
            $selectedRecipient = '';
        }

        if ($selectedRecipient === '') {
            $firstConversation = $conversations[0] ?? null;
            $selectedRecipient = is_array($firstConversation) ? (string) $firstConversation['email'] : '';
        }

        $activeConversation = $this->activeConversation($conversations, $selectedRecipient);

        return new Response($this->view->render('messages/index', [
            'title' => 'module.messages.title',
            'recipients' => $this->messageStore->recipients($email),
            'quickContacts' => $quickContacts,
            'conversations' => $conversations,
            'activeContact' => $selectedRecipient !== '' ? $this->messageStore->contact($selectedRecipient) : null,
            'threadMessages' => $selectedRecipient !== '' ? $this->messageStore->threadMessages($email, $selectedRecipient) : [],
            'replySubject' => $selectedRecipient !== '' ? $this->messageStore->replySubject($email, $selectedRecipient) : '',
            'inbox' => $this->messageStore->inbox($email),
            'sent' => $this->messageStore->sent($email),
            'unreadCount' => $this->messageStore->unreadCount($email),
            'activeConversationUnreadCount' => (int) ($activeConversation['unread_count'] ?? 0),
            'canSend' => $this->auth->can('messaging.send'),
            'canManageDeletedMessages' => $canManageDeletedMessages,
            'deletedMessages' => $canManageDeletedMessages ? $this->messageStore->deletedMessages() : [],
            'conversationCount' => count($conversations),
            'quickContactCount' => count($quickContacts),
            'selectedRecipient' => $selectedRecipient,
        ]));
    }

    public function send(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/messages');
        }

        if (!$this->auth->can('module.messages.access') || !$this->auth->can('messaging.send')) {
            Session::flash('error', 'messages.flash.not_allowed');

            return Response::redirect('/module/messages');
        }

        $result = $this->messageStore->send($this->auth->user() ?? [], $request->all());
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        $toEmail = (string) $request->input('to_email', '');

        if ($result['ok'] && $toEmail !== '') {
            $sender = $this->auth->user() ?? [];
            $this->pushStore->sendToUser($toEmail, [
                'title' => (string) ($sender['name'] ?? 'Kanso Intranet'),
                'body' => substr((string) $request->input('subject', ''), 0, 140) ?: $this->translator->get('messages.push.fallback_subject'),
                'url' => '/module/messages?thread=' . urlencode((string) ($sender['email'] ?? '')),
                'tag' => 'message-' . hash('sha1', (string) ($sender['email'] ?? '') . '-' . $toEmail),
            ]);
        }

        return Response::redirect('/module/messages' . ($toEmail !== '' ? '?thread=' . urlencode($toEmail) : ''));
    }

    public function unreadCount(): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['ok' => false, 'count' => 0], 401);
        }

        if (!$this->auth->can('module.messages.access')) {
            return Response::json(['ok' => false, 'count' => 0], 403);
        }

        return Response::json([
            'ok' => true,
            'count' => $this->messageStore->unreadCount((string) ($this->auth->user()['email'] ?? '')),
        ]);
    }

    public function markThreadRead(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/messages');
        }

        if (!$this->auth->can('module.messages.access')) {
            Session::flash('error', 'messages.flash.not_allowed');

            return Response::redirect('/module/messages');
        }

        $counterpartEmail = (string) $request->input('counterpart_email', '');
        $result = $this->messageStore->markThreadRead((string) ($this->auth->user()['email'] ?? ''), $counterpartEmail);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/messages' . ($counterpartEmail !== '' ? '?thread=' . urlencode($counterpartEmail) : ''));
    }

    public function togglePin(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/messages');
        }

        if (!$this->auth->can('module.messages.access')) {
            Session::flash('error', 'messages.flash.not_allowed');

            return Response::redirect('/module/messages');
        }

        $result = $this->messageStore->togglePin(
            (string) ($this->auth->user()['email'] ?? ''),
            (string) $request->input('counterpart_email')
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/messages');
    }

    public function delete(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/messages');
        }

        if (!$this->auth->can('module.messages.access')) {
            Session::flash('error', 'messages.flash.not_allowed');

            return Response::redirect('/module/messages');
        }

        $result = $this->messageStore->delete(
            $id,
            $this->auth->user() ?? [],
            $this->auth->can('admin.company.manage')
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect($this->messagesRedirect((string) $request->input('redirect_to', '/module/messages')));
    }

    public function restore(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/messages');
        }

        if (!$this->auth->can('module.messages.access') || !$this->auth->can('admin.company.manage')) {
            Session::flash('error', 'messages.flash.not_allowed');

            return Response::redirect('/module/messages');
        }

        $result = $this->messageStore->restore($id, $this->auth->user() ?? []);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect($this->messagesRedirect((string) $request->input('redirect_to', '/module/messages')));
    }

    public function markRead(Request $request, string $id): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/module/messages');
        }

        $result = $this->messageStore->markRead($id, (string) ($this->auth->user()['email'] ?? ''));
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect('/module/messages');
    }

    private function departmentManagerEmail(array $user): ?string
    {
        $policy = $this->accessControl->departmentPolicy((string) ($user['department'] ?? ''));
        $currentEmail = (string) ($user['email'] ?? '');

        foreach (['manager_1_email', 'manager_2_email'] as $key) {
            $managerEmail = (string) ($policy[$key] ?? '');

            if ($managerEmail !== '' && $managerEmail !== $currentEmail) {
                return $managerEmail;
            }
        }

        return null;
    }

    private function activeConversation(array $conversations, string $selectedRecipient): ?array
    {
        foreach ($conversations as $conversation) {
            if (($conversation['email'] ?? '') === $selectedRecipient) {
                return $conversation;
            }
        }

        return null;
    }

    private function messagesRedirect(string $target): string
    {
        return str_starts_with($target, '/module/messages') ? $target : '/module/messages';
    }
}
