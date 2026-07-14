<?php

namespace App\Modules\Messaging;

use App\Core\StateStore;

class MessageStore
{
    private const VERSION = 2;
    private const STATE_KEY = 'messages';
    private array $users;

    public function __construct(
        array $users,
        private readonly StateStore $stateStore,
    ) {
        $this->users = $users;
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $this->data();
    }

    public function replaceDirectoryUsers(array $users): void
    {
        $this->users = $users;
    }

    public function recipients(string $currentEmail): array
    {
        $recipients = [];

        foreach ($this->users as $email => $user) {
            if ($email === $currentEmail) {
                continue;
            }

            if (!filter_var((string) ($user['email'] ?? $email), FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipients[] = [
                'email' => $email,
                'name' => $user['name'],
                'role' => $user['role'],
                'department' => $user['department'],
            ];
        }

        usort($recipients, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $recipients;
    }

    public function inbox(string $email): array
    {
        return $this->messagesFor('to_email', $email);
    }

    public function sent(string $email): array
    {
        return $this->messagesFor('from_email', $email);
    }

    public function unreadCount(string $email): int
    {
        return count(array_filter(
            $this->inbox($email),
            fn (array $message): bool => empty($message['read_at'])
        ));
    }

    public function deletedMessages(): array
    {
        $messages = array_values(array_filter(
            $this->data()['messages'],
            fn (array $message): bool => $this->isDeleted($message)
        ));

        $messages = array_map(function (array $message): array {
            $message['from_label'] = $message['from_name'] ?? ($message['from_email'] ?? '');
            $message['to_label'] = $message['to_name'] ?? ($message['to_email'] ?? '');
            $message['deleted_by_label'] = $message['deleted_by_name'] ?? ($message['deleted_by_email'] ?? '');

            return $message;
        }, $messages);

        usort($messages, fn (array $a, array $b): int => strcmp((string) ($b['deleted_at'] ?? ''), (string) ($a['deleted_at'] ?? '')));

        return $messages;
    }

    public function quickContacts(string $email, ?string $managerEmail): array
    {
        $contacts = [];

        foreach ($this->pinnedEmails($email) as $pinnedEmail) {
            $this->addContact($contacts, $pinnedEmail, 'messages.quick.pinned');
        }

        if (is_string($managerEmail) && $managerEmail !== '') {
            $this->addContact($contacts, $managerEmail, 'messages.quick.manager');
        }

        foreach ($this->lastCorrespondents($email, 3) as $recentEmail) {
            $this->addContact($contacts, $recentEmail, 'messages.quick.recent');
        }

        return array_values($contacts);
    }

    public function conversations(string $email): array
    {
        $conversations = [];

        foreach ($this->activeMessages() as $message) {
            if (($message['from_email'] ?? '') !== $email && ($message['to_email'] ?? '') !== $email) {
                continue;
            }

            $counterpartEmail = $this->counterpartEmail($message, $email);

            if ($counterpartEmail === null || !isset($this->users[$counterpartEmail])) {
                continue;
            }

            if (!isset($conversations[$counterpartEmail])) {
                $conversations[$counterpartEmail] = [
                    'email' => $counterpartEmail,
                    'name' => $this->users[$counterpartEmail]['name'],
                    'department' => $this->users[$counterpartEmail]['department'],
                    'latest_at' => $message['created_at'],
                    'latest_subject' => $message['subject'],
                    'unread_count' => 0,
                    'is_pinned' => $this->isPinned($email, $counterpartEmail),
                ];
            }

            if (strcmp($message['created_at'], $conversations[$counterpartEmail]['latest_at']) > 0) {
                $conversations[$counterpartEmail]['latest_at'] = $message['created_at'];
                $conversations[$counterpartEmail]['latest_subject'] = $message['subject'];
            }

            if (($message['to_email'] ?? '') === $email && empty($message['read_at'])) {
                $conversations[$counterpartEmail]['unread_count']++;
            }
        }

        $conversations = array_values($conversations);
        usort($conversations, function (array $a, array $b): int {
            if ($a['is_pinned'] !== $b['is_pinned']) {
                return $a['is_pinned'] ? -1 : 1;
            }

            return strcmp($b['latest_at'], $a['latest_at']);
        });

        return $conversations;
    }

    public function contact(string $email): ?array
    {
        if (!isset($this->users[$email])) {
            return null;
        }

        return [
            'email' => $email,
            'name' => $this->users[$email]['name'],
            'role' => $this->users[$email]['role'],
            'department' => $this->users[$email]['department'],
        ];
    }

    public function threadMessages(string $email, string $counterpartEmail): array
    {
        if (!isset($this->users[$counterpartEmail]) || $counterpartEmail === $email) {
            return [];
        }

        $messages = array_values(array_filter(
            $this->activeMessages(),
            fn (array $message): bool => $this->counterpartEmail($message, $email) === $counterpartEmail
        ));

        usort($messages, fn (array $a, array $b): int => strcmp($a['created_at'], $b['created_at']));

        return array_map(function (array $message) use ($email, $counterpartEmail): array {
            $isMine = ($message['from_email'] ?? '') === $email;
            $message['is_mine'] = $isMine;
            $message['is_unread'] = !$isMine && empty($message['read_at']);
            $message['speaker_name'] = $isMine ? ($message['from_name'] ?? '') : ($message['from_name'] ?? $this->users[$counterpartEmail]['name']);
            $message['counterpart_email'] = $counterpartEmail;
            $message['is_pinned'] = $this->isPinned($email, $counterpartEmail);

            return $message;
        }, $messages);
    }

    public function replySubject(string $email, string $counterpartEmail): string
    {
        $messages = $this->threadMessages($email, $counterpartEmail);
        $latest = end($messages);

        if (!is_array($latest) || trim((string) ($latest['subject'] ?? '')) === '') {
            return 'Konusma';
        }

        $subject = (string) $latest['subject'];

        return str_starts_with($subject, 'Re: ') ? $subject : 'Re: ' . $subject;
    }

    public function togglePin(string $email, string $counterpartEmail): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());

        if (!isset($this->users[$counterpartEmail]) || $counterpartEmail === $email) {
            return ['ok' => false, 'message' => 'messages.flash.invalid_pin'];
        }

        $data = $this->data();
        $pins = $data['pinned_conversations'][$email] ?? [];

        if (in_array($counterpartEmail, $pins, true)) {
            $pins = array_values(array_filter($pins, fn (string $pin): bool => $pin !== $counterpartEmail));
            $message = 'messages.flash.unpinned';
        } else {
            array_unshift($pins, $counterpartEmail);
            $pins = array_values(array_unique($pins));
            $message = 'messages.flash.pinned';
        }

        $data['pinned_conversations'][$email] = $pins;
        $this->saveData($data);

        return ['ok' => true, 'message' => $message];
    }

    public function send(array $sender, array $input): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $toEmail = trim((string) ($input['to_email'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        if (!isset($this->users[$toEmail]) || $toEmail === ($sender['email'] ?? '')) {
            return ['ok' => false, 'message' => 'messages.flash.invalid_recipient'];
        }

        if ($subject === '' || $body === '') {
            return ['ok' => false, 'message' => 'messages.flash.empty'];
        }

        $data = $this->data();
        $message = [
            'id' => $this->nextId($data['messages']),
            'from_email' => $sender['email'],
            'from_name' => $sender['name'],
            'to_email' => $toEmail,
            'to_name' => $this->users[$toEmail]['name'],
            'subject' => substr($subject, 0, 160),
            'body' => substr($body, 0, 2000),
            'created_at' => date('Y-m-d H:i'),
            'read_at' => null,
            'deleted_at' => null,
            'deleted_by_email' => null,
            'deleted_by_name' => null,
            'restored_at' => null,
            'restored_by_email' => null,
            'restored_by_name' => null,
        ];

        $data['messages'][] = $message;
        $this->saveData($data);

        return ['ok' => true, 'message' => 'messages.flash.sent'];
    }

    public function delete(string $id, array $actor, bool $isAdmin = false): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $actorEmail = (string) ($actor['email'] ?? '');
        $data = $this->data();

        foreach ($data['messages'] as $index => $message) {
            if (($message['id'] ?? '') !== $id || $this->isDeleted($message)) {
                continue;
            }

            $isParticipant = in_array($actorEmail, [(string) ($message['from_email'] ?? ''), (string) ($message['to_email'] ?? '')], true);

            if (!$isParticipant && !$isAdmin) {
                return ['ok' => false, 'message' => 'messages.flash.not_allowed'];
            }

            $data['messages'][$index]['deleted_at'] = date('Y-m-d H:i');
            $data['messages'][$index]['deleted_by_email'] = $actorEmail;
            $data['messages'][$index]['deleted_by_name'] = (string) ($actor['name'] ?? $actorEmail);
            $this->saveData($data);

            return ['ok' => true, 'message' => 'messages.flash.deleted'];
        }

        return ['ok' => false, 'message' => 'messages.flash.not_found'];
    }

    public function restore(string $id, array $actor): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $data = $this->data();

        foreach ($data['messages'] as $index => $message) {
            if (($message['id'] ?? '') !== $id || !$this->isDeleted($message)) {
                continue;
            }

            $data['messages'][$index]['deleted_at'] = null;
            $data['messages'][$index]['restored_at'] = date('Y-m-d H:i');
            $data['messages'][$index]['restored_by_email'] = (string) ($actor['email'] ?? '');
            $data['messages'][$index]['restored_by_name'] = (string) ($actor['name'] ?? ($actor['email'] ?? ''));
            $this->saveData($data);

            return ['ok' => true, 'message' => 'messages.flash.restored'];
        }

        return ['ok' => false, 'message' => 'messages.flash.not_found'];
    }

    public function markRead(string $id, string $email): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $data = $this->data();

        foreach ($data['messages'] as $index => $message) {
            if ($message['id'] !== $id || $message['to_email'] !== $email || $this->isDeleted($message)) {
                continue;
            }

            $data['messages'][$index]['read_at'] = $message['read_at'] ?: date('Y-m-d H:i');
            $this->saveData($data);

            return ['ok' => true, 'message' => 'messages.flash.read'];
        }

        return ['ok' => false, 'message' => 'messages.flash.not_found'];
    }

    public function markThreadRead(string $email, string $counterpartEmail): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());

        if (!isset($this->users[$counterpartEmail]) || $counterpartEmail === $email) {
            return ['ok' => false, 'message' => 'messages.flash.not_found'];
        }

        $data = $this->data();
        $changed = false;

        foreach ($data['messages'] as $index => $message) {
            if ($this->isDeleted($message) || ($message['to_email'] ?? '') !== $email || $this->counterpartEmail($message, $email) !== $counterpartEmail || !empty($message['read_at'])) {
                continue;
            }

            $data['messages'][$index]['read_at'] = date('Y-m-d H:i');
            $changed = true;
        }

        if ($changed) {
            $this->saveData($data);
        }

        return ['ok' => true, 'message' => $changed ? 'messages.flash.thread_read' : 'messages.flash.read'];
    }

    public function migrateUserIdentity(string $oldIdentity, string $newIdentity): array
    {
        if ($oldIdentity === '' || $newIdentity === '' || $oldIdentity === $newIdentity) {
            return ['messages' => 0, 'pins' => 0];
        }

        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
        $data = $this->data();
        $messageReferences = 0;

        foreach ($data['messages'] as $index => $message) {
            foreach (['from_email', 'to_email', 'deleted_by_email', 'restored_by_email'] as $field) {
                if ((string) ($message[$field] ?? '') !== $oldIdentity) {
                    continue;
                }

                $data['messages'][$index][$field] = $newIdentity;
                $messageReferences++;
            }
        }

        $pins = is_array($data['pinned_conversations'] ?? null) ? $data['pinned_conversations'] : [];
        $pinReferences = 0;

        if (array_key_exists($oldIdentity, $pins)) {
            $sourcePins = is_array($pins[$oldIdentity]) ? $pins[$oldIdentity] : [];
            $targetPins = is_array($pins[$newIdentity] ?? null) ? $pins[$newIdentity] : [];
            unset($pins[$oldIdentity]);
            $pins[$newIdentity] = array_values(array_unique(array_filter(
                array_merge($targetPins, $sourcePins),
                static fn (mixed $identity): bool => is_string($identity) && $identity !== $newIdentity
            )));
            $pinReferences++;
        }

        foreach ($pins as $owner => $contacts) {
            if (!is_array($contacts)) {
                $pins[$owner] = [];
                continue;
            }

            foreach ($contacts as $index => $contact) {
                if ((string) $contact !== $oldIdentity) {
                    continue;
                }

                $contacts[$index] = $newIdentity;
                $pinReferences++;
            }

            $pins[$owner] = array_values(array_unique(array_filter(
                $contacts,
                static fn (mixed $identity): bool => is_string($identity) && $identity !== (string) $owner
            )));
        }

        if ($messageReferences > 0 || $pinReferences > 0) {
            $data['pinned_conversations'] = $pins;
            $this->saveData($data);
        }

        return ['messages' => $messageReferences, 'pins' => $pinReferences];
    }

    private function messagesFor(string $field, string $email): array
    {
        $messages = array_values(array_filter(
            $this->activeMessages(),
            fn (array $message): bool => ($message[$field] ?? '') === $email
        ));

        $messages = array_map(function (array $message) use ($email): array {
            $counterpartEmail = $this->counterpartEmail($message, $email);
            $message['counterpart_email'] = $counterpartEmail;
            $message['counterpart_name'] = $counterpartEmail ? ($this->users[$counterpartEmail]['name'] ?? $counterpartEmail) : '';
            $message['is_pinned'] = $counterpartEmail ? $this->isPinned($email, $counterpartEmail) : false;

            return $message;
        }, $messages);

        usort($messages, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $messages;
    }

    private function nextId(array $messages): string
    {
        return 'MSG-2026-' . (1001 + count($messages));
    }

    private function data(): array
    {
        $data = $this->loadData();

        if (!isset($data['messages']) || !is_array($data['messages'])) {
            return $this->seedData();
        }

        $dirty = false;

        if (!isset($data['pinned_conversations']) || !is_array($data['pinned_conversations'])) {
            $data['pinned_conversations'] = [];
            $dirty = true;
        }

        foreach ($data['messages'] as $index => $message) {
            foreach ([
                'deleted_at',
                'deleted_by_email',
                'deleted_by_name',
                'restored_at',
                'restored_by_email',
                'restored_by_name',
            ] as $key) {
                if (!array_key_exists($key, $message)) {
                    $data['messages'][$index][$key] = null;
                    $dirty = true;
                }
            }
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            $data['version'] = self::VERSION;
            $dirty = true;
        }

        if ($dirty) {
            $this->saveData($data);
        }

        return $data;
    }

    private function seedData(): array
    {
        $data = [
            'version' => self::VERSION,
            'messages' => [],
            'pinned_conversations' => [],
        ];

        $this->saveData($data);

        return $data;
    }

    private function loadData(): array
    {
        return $this->stateStore->read(self::STATE_KEY, $this->dataPath());
    }

    private function saveData(array $data): void
    {
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), $data);
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/messages.json';
    }

    private function pinnedEmails(string $email): array
    {
        $pins = $this->data()['pinned_conversations'][$email] ?? [];

        return is_array($pins) ? $pins : [];
    }

    private function isPinned(string $email, string $counterpartEmail): bool
    {
        return in_array($counterpartEmail, $this->pinnedEmails($email), true);
    }

    private function lastCorrespondents(string $email, int $limit): array
    {
        $latest = [];

        foreach ($this->data()['messages'] as $message) {
            if ($this->isDeleted($message)) {
                continue;
            }

            $counterpartEmail = $this->counterpartEmail($message, $email);

            if ($counterpartEmail === null) {
                continue;
            }

            if (!isset($latest[$counterpartEmail]) || strcmp($message['created_at'], $latest[$counterpartEmail]) > 0) {
                $latest[$counterpartEmail] = $message['created_at'];
            }
        }

        arsort($latest);

        return array_slice(array_keys($latest), 0, $limit);
    }

    private function addContact(array &$contacts, string $email, string $reasonKey): void
    {
        if (!isset($this->users[$email]) || isset($contacts[$email])) {
            return;
        }

        $contacts[$email] = [
            'email' => $email,
            'name' => $this->users[$email]['name'],
            'department' => $this->users[$email]['department'],
            'role' => $this->users[$email]['role'],
            'reason_key' => $reasonKey,
        ];
    }

    private function counterpartEmail(array $message, string $email): ?string
    {
        if (($message['from_email'] ?? '') === $email) {
            return $message['to_email'] ?? null;
        }

        if (($message['to_email'] ?? '') === $email) {
            return $message['from_email'] ?? null;
        }

        return null;
    }

    private function activeMessages(): array
    {
        return array_values(array_filter(
            $this->data()['messages'],
            fn (array $message): bool => !$this->isDeleted($message)
        ));
    }

    private function isDeleted(array $message): bool
    {
        return !empty($message['deleted_at']);
    }
}
