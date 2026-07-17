<?php

namespace App\Modules\Leave;

use App\Core\AccessControl;
use App\Core\Auth;
use App\Core\IdentityReferenceRewriter;
use App\Core\LocationScope;
use App\Core\Session;
use App\Core\StateStore;
use App\Core\StateWriteGuard;
use App\Core\UserProfileStore;
use App\Modules\Shift\ShiftStore;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

class LeaveStore
{
    private const SESSION_KEY = 'leave_requests';
    private const STORAGE_VERSION = 1;
    private const REQUESTS_STATE_KEY = 'leave_requests';
    private const MAIL_OUTBOX_STATE_KEY = 'leave_mail_outbox';
    private const TOKEN_TTL_HOURS = 96;
    private const LEAVE_BOOK_SIGNATURE_FOLLOWUP_DAYS = 2;
    private const LEAVE_BOOK_SIGNATURE_TOKEN_TTL_DAYS = 14;
    private const DAY_PART_FULL = 'full';
    private const DAY_PART_MORNING = 'morning';
    private const DAY_PART_AFTERNOON = 'afternoon';
    private const DAY_PARTS = [
        self::DAY_PART_FULL,
        self::DAY_PART_MORNING,
        self::DAY_PART_AFTERNOON,
    ];
    private const CANCELLATION_STAGE = 'cancellation_manager_1';
    private const ENTITLEMENT_BANDS = [
        [
            'min_year' => 1,
            'max_year' => 5,
            'days' => 14,
            'label_key' => 'leave.entitlement.rule.one_to_five',
        ],
        [
            'min_year' => 6,
            'max_year' => 14,
            'days' => 20,
            'label_key' => 'leave.entitlement.rule.more_than_five',
        ],
        [
            'min_year' => 15,
            'max_year' => null,
            'days' => 26,
            'label_key' => 'leave.entitlement.rule.fifteen_plus',
        ],
    ];
    private const AGE_MINIMUM_ENTITLEMENT = [
        'days' => 20,
        'label_key' => 'leave.entitlement.rule.age',
    ];

    public function __construct(
        private readonly ?AccessControl $accessControl,
        private readonly ?LeaveApprovalMailer $approvalMailer,
        private readonly StateStore $stateStore,
        private readonly UserProfileStore $userProfiles,
        private readonly ShiftStore $shiftStore,
    ) {
        $writeGuard = $this->stateStore->beginWrite(self::REQUESTS_STATE_KEY, $this->requestsPath());
        $storedRequests = $this->loadRequests();
        $normalizedRequests = $this->all();

        if ($storedRequests !== $normalizedRequests) {
            $this->save($normalizedRequests);
        }

        $this->redactStoredMailOutbox();
    }

    public function all(): array
    {
        $requests = $this->loadRequests();

        if ($requests === null) {
            $sessionRequests = Session::get(self::SESSION_KEY);
            $requests = is_array($sessionRequests) ? $sessionRequests : $this->seed();
        }

        $requests = $this->normalizeRequests($requests);
        usort($requests, fn (array $a, array $b): int => strcmp($a['starts_on'], $b['starts_on']));

        return $requests;
    }

    public function findById(string $id): ?array
    {
        foreach ($this->all() as $request) {
            if ((string) ($request['id'] ?? '') === $id) {
                return $request;
            }
        }

        return null;
    }

    public function pendingLeaveBookSignatureAlertsForUser(array $user, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $alerts = [];

        foreach ($this->all() as $request) {
            if (!$this->shouldTrackLeaveBookSignature($request)) {
                continue;
            }

            if (!$this->requestBelongsToUser($request, $user)) {
                continue;
            }

            $signature = $this->normalizeLeaveBookSignatureState($request);

            if ((string) ($signature['status'] ?? 'waiting') !== 'waiting') {
                continue;
            }

            $notificationDueAt = $this->dateTimeOrNull((string) ($signature['notification_due_at'] ?? ''));

            if ($notificationDueAt !== null && $notificationDueAt > $now) {
                continue;
            }

            $alerts[] = [
                'id' => (string) ($request['id'] ?? ''),
                'starts_on' => (string) ($request['starts_on'] ?? ''),
                'ends_on' => (string) ($request['ends_on'] ?? ''),
                'total_days' => (float) ($request['total_days'] ?? 0),
                'total_days_label' => $this->formatDays($request['total_days'] ?? 0),
                'day_part' => $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL),
                'day_part_key' => $this->dayPartKey($request),
                'notification_due_at' => (string) ($signature['notification_due_at'] ?? ''),
                'due_at' => (string) ($signature['due_at'] ?? ''),
                'required_at' => (string) ($signature['required_at'] ?? ''),
            ];
        }

        usort($alerts, function (array $a, array $b): int {
            $due = strcmp((string) ($a['notification_due_at'] ?? ''), (string) ($b['notification_due_at'] ?? ''));

            return $due !== 0 ? $due : strcmp((string) ($a['ends_on'] ?? ''), (string) ($b['ends_on'] ?? ''));
        });

        return $alerts;
    }

    public function create(array $user, array $input): array
    {
        $writeGuard = $this->writeGuard();
        $requests = $this->all();
        $startsOn = $this->cleanDate((string) ($input['starts_on'] ?? ''));
        $endsOn = $this->cleanDate((string) ($input['ends_on'] ?? ''));

        if ($startsOn === null || $endsOn === null || $startsOn > $endsOn) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_dates'];
        }

        $typeKey = (string) ($input['type_key'] ?? 'leave.type.annual');
        $dayPart = $this->cleanDayPart($input['day_part'] ?? self::DAY_PART_FULL);

        if ($this->isHalfDayPart($dayPart) && $startsOn !== $endsOn) {
            return ['ok' => false, 'message' => 'leave.flash.half_day_single_date'];
        }

        $totalDays = $this->leaveDurationDays($startsOn, $endsOn, $dayPart, $user);

        if ($totalDays <= 0) {
            return ['ok' => false, 'message' => 'leave.flash.no_working_day'];
        }

        if ($typeKey === 'leave.type.annual') {
            $balance = $this->balanceForUser($user);

            if ($totalDays > (float) ($balance['remaining_days'] ?? 0)) {
                return ['ok' => false, 'message' => 'leave.flash.insufficient_balance'];
            }
        }

        $policy = $this->accessControl?->departmentPolicy($user['department'] ?? 'General') ?? [];
        $managerCount = (int) ($policy['manager_approval_count'] ?? ($input['manager_count'] ?? 1));
        $managerCount = $managerCount === 2 ? 2 : 1;

        $request = [
            'id' => $this->nextId($requests),
            'requester_id' => $this->personnelIdForUser($user),
            'requester' => $user['name'] ?? 'Unknown',
            'requester_email' => $user['email'] ?? '',
            'department' => $user['department'] ?? 'General',
            'requester_location' => LocationScope::locationForProfile($user),
            'type_key' => $typeKey,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'day_part' => $dayPart,
            'total_days' => $totalDays,
            'manager_count' => $managerCount,
            'approval_policy' => [
                'manager_1_email' => $policy['manager_1_email'] ?? '',
                'manager_2_email' => $managerCount === 2 ? ($policy['manager_2_email'] ?? '') : '',
                'hr_email' => $policy['hr_email'] ?? '',
            ],
            'status' => 'waiting_manager_1',
            'calendar_state' => 'tentative',
            'note' => trim((string) ($input['note'] ?? '')),
            'created_at' => date('Y-m-d H:i'),
            'approval_tokens' => [
                'manager_1' => null,
                'manager_2' => null,
                'hr' => null,
            ],
            'approval_token_expires_at' => [
                'manager_1' => null,
                'manager_2' => null,
                'hr' => null,
            ],
            'mail_notifications' => [],
            'approvals' => $this->initialApprovals($managerCount, $policy),
        ];
        $request = $this->activateCurrentStageToken($request, true);
        $request = $this->queueRequesterReceiptMail($request, $user);

        $requests[] = $request;
        $this->save($requests);

        return [
            'ok' => true,
            'message' => 'leave.flash.created',
            'notifications' => $this->notificationsForCurrentStage($request),
        ];
    }

    public function requesterEditableRequestsFor(array $user): array
    {
        $requests = [];

        foreach ($this->all() as $request) {
            if (!$this->canRequesterEditBeforeFirstApproval($request, $user)) {
                continue;
            }

            $requests[] = $this->decorateForRequester($request);
        }

        usort($requests, fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $requests;
    }

    public function requesterCancellableRequestsFor(array $user): array
    {
        $requests = [];

        foreach ($this->all() as $request) {
            if (!$this->canRequesterAskCancellationAfterFirstApproval($request, $user)) {
                continue;
            }

            $requests[] = $this->decorateForRequester($request);
        }

        usort($requests, fn (array $a, array $b): int => strcmp((string) ($a['starts_on'] ?? ''), (string) ($b['starts_on'] ?? '')));

        return $requests;
    }

    public function requesterHistoryRequestsFor(array $user): array
    {
        $requests = [];

        foreach ($this->all() as $request) {
            if (!$this->requestBelongsToUser($request, $user)) {
                continue;
            }

            $requests[] = $this->decorateForRequester($request);
        }

        usort($requests, function (array $a, array $b): int {
            $created = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));

            return $created !== 0 ? $created : strcmp((string) ($b['starts_on'] ?? ''), (string) ($a['starts_on'] ?? ''));
        });

        return $requests;
    }

    public function updateByRequesterBeforeFirstApproval(string $id, array $user, array $input): array
    {
        $writeGuard = $this->writeGuard();
        $requests = $this->all();
        $startsOn = $this->cleanDate((string) ($input['starts_on'] ?? ''));
        $endsOn = $this->cleanDate((string) ($input['ends_on'] ?? ''));

        if ($startsOn === null || $endsOn === null || $startsOn > $endsOn) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_dates'];
        }

        $typeKey = (string) ($input['type_key'] ?? 'leave.type.annual');
        $dayPart = $this->cleanDayPart($input['day_part'] ?? self::DAY_PART_FULL);

        if ($this->isHalfDayPart($dayPart) && $startsOn !== $endsOn) {
            return ['ok' => false, 'message' => 'leave.flash.half_day_single_date'];
        }

        $totalDays = $this->leaveDurationDays($startsOn, $endsOn, $dayPart, $user);

        if ($totalDays <= 0) {
            return ['ok' => false, 'message' => 'leave.flash.no_working_day'];
        }

        foreach ($requests as $index => $request) {
            if ((string) ($request['id'] ?? '') !== $id) {
                continue;
            }

            if (!$this->canRequesterEditBeforeFirstApproval($request, $user)) {
                return ['ok' => false, 'message' => 'leave.flash.edit_locked'];
            }

            if ($typeKey === 'leave.type.annual') {
                $balance = $this->balanceForUser($user, $id);

                if ($totalDays > (float) ($balance['remaining_days'] ?? 0)) {
                    return ['ok' => false, 'message' => 'leave.flash.insufficient_balance'];
                }
            }

            $request['type_key'] = $typeKey;
            $request['starts_on'] = $startsOn;
            $request['ends_on'] = $endsOn;
            $request['day_part'] = $dayPart;
            $request['total_days'] = $totalDays;
            $request['note'] = trim((string) ($input['note'] ?? ($request['note'] ?? '')));
            $request['updated_at'] = date('Y-m-d H:i');
            $request['updated_by'] = (string) ($user['name'] ?? $request['requester'] ?? '');
            $request['updated_source'] = 'requester';

            $requests[$index] = $request;
            $this->save($requests);

            return [
                'ok' => true,
                'message' => 'leave.flash.updated_no_notification',
                'request' => $requests[$index],
                'notifications' => [],
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.not_found'];
    }

    public function cancelByRequesterBeforeFirstApproval(string $id, array $user): array
    {
        $writeGuard = $this->writeGuard();
        $requests = $this->all();

        foreach ($requests as $index => $request) {
            if ((string) ($request['id'] ?? '') !== $id) {
                continue;
            }

            if (!$this->canRequesterEditBeforeFirstApproval($request, $user)) {
                return ['ok' => false, 'message' => 'leave.flash.edit_locked'];
            }

            $requests[$index] = $this->cancelRequest($request, (string) ($user['name'] ?? 'Requester'), 'requester');
            $this->save($requests);

            return [
                'ok' => true,
                'message' => 'leave.flash.requester_cancelled',
                'request' => $requests[$index],
                'notifications' => [],
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.not_found'];
    }

    public function requestCancellationByRequester(string $id, array $user): array
    {
        $writeGuard = $this->writeGuard();
        $requests = $this->all();

        foreach ($requests as $index => $request) {
            if ((string) ($request['id'] ?? '') !== $id) {
                continue;
            }

            if (!$this->requestBelongsToUser($request, $user)) {
                return ['ok' => false, 'message' => 'leave.flash.not_allowed'];
            }

            if ($this->cancellationPending($request)) {
                return ['ok' => false, 'message' => 'leave.flash.cancellation_already_pending'];
            }

            if (!$this->firstManagerApproved($request) || !in_array((string) ($request['status'] ?? ''), ['waiting_manager_2', 'waiting_hr', 'approved'], true)) {
                return ['ok' => false, 'message' => 'leave.flash.cancellation_not_available'];
            }

            $managerEmail = $this->assigneeEmailForStage($request, 'manager_1');
            $request['cancellation_request'] = [
                'status' => 'pending',
                'requested_at' => date('Y-m-d H:i'),
                'requested_by' => (string) ($user['name'] ?? ''),
                'requested_by_email' => (string) ($user['email'] ?? ''),
                'approver_email' => $managerEmail,
                'original_status' => (string) ($request['status'] ?? ''),
                'original_calendar_state' => (string) ($request['calendar_state'] ?? ''),
                'acted_at' => null,
                'acted_by' => null,
                'source' => null,
            ];
            $rawToken = $this->newOpaqueToken();
            $request['approval_tokens'][self::CANCELLATION_STAGE] = $this->hashOpaqueToken($rawToken);
            $request['approval_token_expires_at'][self::CANCELLATION_STAGE] = $this->tokenExpiresAt();
            $request = $this->queueApprovalMail($request, self::CANCELLATION_STAGE, $rawToken);

            $requests[$index] = $request;
            $this->save($requests);

            return [
                'ok' => true,
                'message' => 'leave.flash.cancellation_requested',
                'request' => $requests[$index],
                'notifications' => $this->notificationsForCurrentStage($requests[$index]),
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.not_found'];
    }

    public function advanceByPlatform(string $id, array $actor, Auth $auth, string $decision, string $decisionNote = ''): array
    {
        $writeGuard = $this->writeGuard();

        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_decision'];
        }

        $decisionNote = $this->cleanDecisionNote($decisionNote);

        if ($decision === 'reject' && $decisionNote === '') {
            return ['ok' => false, 'message' => 'leave.flash.reject_reason_required'];
        }

        $requests = $this->all();

        foreach ($requests as $index => $request) {
            if ($request['id'] !== $id) {
                continue;
            }

            $stage = $this->currentStage($request);

            if ($stage === null || !$this->canActOnStage($stage, $auth, $request)) {
                return ['ok' => false, 'message' => 'leave.flash.not_allowed'];
            }

            $isCancellationDecision = $stage === self::CANCELLATION_STAGE;
            $requests[$index] = $this->applyDecision($request, $stage, $decision, $actor['name'] ?? 'Platform', 'platform', $decisionNote);
            $this->save($requests);

            return [
                'ok' => true,
                'message' => $isCancellationDecision
                    ? ($decision === 'approve' ? 'leave.flash.cancellation_approved' : 'leave.flash.cancellation_rejected')
                    : ($decision === 'approve' ? 'leave.flash.approved' : 'leave.flash.rejected'),
                'notifications' => $this->notificationsForCurrentStage($requests[$index]),
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.not_found'];
    }

    public function previewTokenDecision(string $token, string $decision): array
    {
        if (!in_array($decision, ['approve', 'reject'], true) || !$this->validOpaqueToken($token)) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_decision', 'request' => null];
        }

        foreach ($this->all() as $request) {
            $stage = $this->currentStage($request);

            if ($stage === null || !$this->opaqueTokenMatches((string) ($request['approval_tokens'][$stage] ?? ''), $token)) {
                continue;
            }

            if ($this->tokenExpired($request, $stage)) {
                return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => $this->tokenResultRequest($request)];
            }

            if (!$this->assigneeCanViewRequest($request, $stage)) {
                return ['ok' => false, 'message' => 'leave.flash.not_allowed', 'request' => null];
            }

            return [
                'ok' => true,
                'message' => 'leave.mail.confirm_prompt',
                'request' => $this->tokenResultRequest($request),
                'stage' => $stage,
                'decision' => $decision,
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => null];
    }

    public function advanceByToken(string $token, string $decision, string $decisionNote = ''): array
    {
        $writeGuard = $this->writeGuard();

        if (!in_array($decision, ['approve', 'reject'], true) || !$this->validOpaqueToken($token)) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_decision', 'request' => null];
        }

        $decisionNote = $this->cleanDecisionNote($decisionNote);

        if ($decision === 'reject' && $decisionNote === '') {
            return ['ok' => false, 'message' => 'leave.flash.reject_reason_required', 'request' => null];
        }

        $requests = $this->all();

        foreach ($requests as $index => $request) {
            $stage = $this->currentStage($request);

            if ($stage === null || !$this->opaqueTokenMatches((string) ($request['approval_tokens'][$stage] ?? ''), $token)) {
                continue;
            }

            if ($this->tokenExpired($request, $stage)) {
                return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => $request];
            }

            if (!$this->assigneeCanViewRequest($request, $stage)) {
                return ['ok' => false, 'message' => 'leave.flash.not_allowed', 'request' => $request];
            }

            $isCancellationDecision = $stage === self::CANCELLATION_STAGE;
            $requests[$index] = $this->applyDecision($request, $stage, $decision, 'Mail approval', 'email', $decisionNote);
            $this->save($requests);

            return [
                'ok' => true,
                'message' => $isCancellationDecision
                    ? ($decision === 'approve' ? 'leave.flash.cancellation_approved' : 'leave.flash.cancellation_rejected')
                    : ($decision === 'approve' ? 'leave.flash.mail_approved' : 'leave.flash.mail_rejected'),
                'request' => $this->tokenResultRequest($requests[$index]),
                'notifications' => $this->notificationsForCurrentStage($requests[$index]),
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => null];
    }

    public function previewLeaveBookSignatureToken(string $token, string $decision): array
    {
        $status = match ($decision) {
            'signed' => 'signed',
            'not-signed', 'not_signed' => 'not_signed',
            default => null,
        };

        if ($status === null || !$this->validOpaqueToken($token)) {
            return ['ok' => false, 'message' => 'leave.flash.signature_invalid_decision', 'request' => null];
        }

        foreach ($this->all() as $request) {
            $signature = $this->normalizeLeaveBookSignatureState($request);

            if (!$this->opaqueTokenMatches((string) ($signature['followup_token'] ?? ''), $token)) {
                continue;
            }

            $expiresAt = $this->dateTimeOrNull((string) ($signature['followup_token_expires_at'] ?? ''));

            if ($expiresAt !== null && $expiresAt < new DateTimeImmutable('now')) {
                return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => $this->tokenResultRequest($request)];
            }

            return [
                'ok' => true,
                'message' => 'leave.mail.signature_confirm_prompt',
                'request' => $this->tokenResultRequest($request),
                'decision' => $decision,
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => null];
    }

    public function markLeaveBookSignatureByToken(string $token, string $decision): array
    {
        $writeGuard = $this->writeGuard();
        $status = match ($decision) {
            'signed' => 'signed',
            'not-signed', 'not_signed' => 'not_signed',
            default => null,
        };

        if (!$this->validOpaqueToken($token) || $status === null) {
            return ['ok' => false, 'message' => 'leave.flash.signature_invalid_decision', 'request' => null];
        }

        $requests = $this->all();

        foreach ($requests as $index => $request) {
            $signature = $this->normalizeLeaveBookSignatureState($request);

            if (!$this->opaqueTokenMatches((string) ($signature['followup_token'] ?? ''), $token)) {
                continue;
            }

            $expiresAt = $this->dateTimeOrNull((string) ($signature['followup_token_expires_at'] ?? ''));

            if ($expiresAt !== null && $expiresAt < new DateTimeImmutable('now')) {
                return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => $request];
            }

            $signature['status'] = $status;
            $signature['acted_at'] = date('Y-m-d H:i');
            $signature['acted_by'] = 'Mail follow-up';
            $signature['source'] = 'email';
            $signature['followup_token'] = null;
            $signature['followup_token_expires_at'] = null;
            $request['leave_book_signature'] = $signature;
            $requests[$index] = $request;
            $this->save($requests);

            return [
                'ok' => true,
                'message' => $status === 'signed' ? 'leave.flash.signature_signed' : 'leave.flash.signature_not_signed',
                'request' => $this->tokenResultRequest($requests[$index]),
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => null];
    }

    public function sendDueLeaveBookSignatureFollowups(?DateTimeImmutable $now = null): array
    {
        $writeGuard = $this->writeGuard();
        $now ??= new DateTimeImmutable('now');
        $requests = $this->all();
        $result = [
            'checked' => 0,
            'sent' => 0,
            'queued' => 0,
            'skipped' => 0,
            'failed' => 0,
            'messages' => [],
        ];
        $dirty = false;

        foreach ($requests as $index => $request) {
            if (!$this->shouldTrackLeaveBookSignature($request)) {
                continue;
            }

            $result['checked']++;
            $signature = $this->normalizeLeaveBookSignatureState($request);
            $request['leave_book_signature'] = $signature;
            $signatureNotificationQueued = $this->signatureNotificationQueued($request);

            if (!$signatureNotificationQueued) {
                $notificationDueAt = $this->dateTimeOrNull((string) ($signature['notification_due_at'] ?? '')) ?? $now;

                if ($notificationDueAt > $now) {
                    $result['skipped']++;
                    $requests[$index] = $request;
                    $dirty = true;

                    continue;
                }

                $request = $this->deliverLeaveBookSignatureMail($request, $now);
                $requests[$index] = $request;
                $dirty = true;
                $notification = $request['mail_notifications']['leave_book_signature'] ?? [];
                $status = (string) ($notification['status'] ?? 'queued');

                if ($status === 'sent') {
                    $result['sent']++;
                } elseif (in_array($status, ['queued', 'partial'], true)) {
                    $result['queued']++;
                } else {
                    $result['failed']++;
                }

                $result['messages'][] = [
                    'request_id' => (string) ($request['id'] ?? ''),
                    'type' => 'leave_book_signature_required',
                    'to_email' => (string) ($notification['to_email'] ?? ''),
                    'status' => $status,
                    'transport' => (string) ($notification['transport'] ?? ''),
                ];

                continue;
            }

            if ((string) ($signature['status'] ?? 'waiting') !== 'waiting') {
                $result['skipped']++;
                $requests[$index] = $request;
                $dirty = true;

                continue;
            }

            if ((string) ($signature['followup_queued_at'] ?? '') !== '') {
                $result['skipped']++;
                $requests[$index] = $request;
                $dirty = true;

                continue;
            }

            $dueAt = $this->dateTimeOrNull((string) ($signature['followup_due_at'] ?? '')) ?? $now;

            if ($dueAt > $now) {
                $result['skipped']++;
                $requests[$index] = $request;
                $dirty = true;

                continue;
            }

            $recipients = $this->signatureFollowupRecipients($request);

            if ($recipients === []) {
                $result['skipped']++;
                $result['messages'][] = [
                    'request_id' => (string) ($request['id'] ?? ''),
                    'status' => 'no_followup_recipient',
                ];
                $requests[$index] = $request;
                $dirty = true;

                continue;
            }

            $request = $this->queueLeaveBookSignatureFollowupMail($request, $recipients, $now);
            $requests[$index] = $request;
            $dirty = true;
            $notification = $request['mail_notifications']['leave_book_signature_followup'] ?? [];
            $status = (string) ($notification['status'] ?? 'queued');

            if ($status === 'sent') {
                $result['sent']++;
            } elseif (in_array($status, ['queued', 'partial'], true)) {
                $result['queued']++;
            } else {
                $result['failed']++;
            }

            $result['messages'][] = [
                'request_id' => (string) ($request['id'] ?? ''),
                'type' => 'leave_book_signature_followup',
                'to_email' => (string) ($notification['to_email'] ?? ''),
                'status' => $status,
                'transport' => (string) ($notification['transport'] ?? ''),
            ];
        }

        if ($dirty) {
            $this->save($requests);
        }

        return $result;
    }

    public function decorateForUser(array $request, Auth $auth): array
    {
        $stage = $this->currentStage($request);
        $cancellationPending = $this->cancellationPending($request);
        $request['current_stage'] = $stage;
        $request['current_stage_key'] = $stage ? 'leave.stage.' . $stage : 'leave.stage.done';
        $request['status_key'] = $cancellationPending ? 'leave.status.cancellation_pending' : 'leave.status.' . $request['status'];
        $request['display_status'] = $cancellationPending ? 'cancellation_pending' : (string) ($request['status'] ?? '');
        $request['cancellation_pending'] = $cancellationPending;
        $request['cancellation_request'] = $this->normalizeCancellationRequest($request);
        $request['day_part'] = $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL);
        $request['day_part_key'] = $this->dayPartKey($request);
        $request['total_days'] = (float) ($request['total_days'] ?? 0);
        $request['total_days_label'] = $this->formatDays($request['total_days']);
        $request['can_act'] = $stage !== null && $this->canActOnStage($stage, $auth, $request);
        $request['mail_token_expires_at'] = $stage ? ($request['approval_token_expires_at'][$stage] ?? null) : null;
        $request['history'] = $this->historyForRequest($request);

        return $request;
    }

    public function pendingApprovalsFor(Auth $auth): array
    {
        $pending = [];

        foreach ($this->all() as $request) {
            $stage = $this->currentStage($request);

            if ($stage === null || !$this->canActOnStage($stage, $auth, $request)) {
                continue;
            }

            $pending[] = $this->decorateForUser($request, $auth);
        }

        usort($pending, function (array $a, array $b): int {
            $created = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));

            return $created !== 0 ? $created : strcmp((string) ($a['starts_on'] ?? ''), (string) ($b['starts_on'] ?? ''));
        });

        return $pending;
    }

    public function pendingLeaveBookSignaturesFor(Auth $auth): array
    {
        if (!$this->canManageLeaveBookSignatures($auth)) {
            return [];
        }

        $pending = [];

        foreach ($this->all() as $request) {
            if (!$this->shouldTrackLeaveBookSignature($request)) {
                continue;
            }

            $signature = $this->normalizeLeaveBookSignatureState($request);

            if ((string) ($signature['status'] ?? 'waiting') !== 'waiting') {
                continue;
            }

            $request['leave_book_signature'] = $signature;
            $pending[] = $this->decorateForLeaveBookSignatureQueue($request, $signature);
        }

        usort($pending, function (array $a, array $b): int {
            $due = strcmp((string) ($a['signature_sort_at'] ?? ''), (string) ($b['signature_sort_at'] ?? ''));

            return $due !== 0 ? $due : strcmp((string) ($a['starts_on'] ?? ''), (string) ($b['starts_on'] ?? ''));
        });

        return $pending;
    }

    public function cancellableRequestsFor(Auth $auth): array
    {
        $requests = [];

        foreach ($this->all() as $request) {
            if (!$this->canCancelRequest($auth, $request)) {
                continue;
            }

            $requests[] = $this->decorateForUser($request, $auth);
        }

        usort($requests, function (array $a, array $b): int {
            $starts = strcmp((string) ($a['starts_on'] ?? ''), (string) ($b['starts_on'] ?? ''));

            return $starts !== 0 ? $starts : strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $requests;
    }

    public function cancelByPlatform(string $id, array $actor, Auth $auth): array
    {
        $writeGuard = $this->writeGuard();
        $requests = $this->all();

        foreach ($requests as $index => $request) {
            if ($request['id'] !== $id) {
                continue;
            }

            if (!$this->canCancelRequest($auth, $request)) {
                return ['ok' => false, 'message' => 'leave.flash.not_allowed', 'request' => $request];
            }

            $requests[$index] = $this->cancelRequest($request, $actor['name'] ?? 'Platform', 'platform');
            $this->save($requests);

            return ['ok' => true, 'message' => 'leave.flash.cancelled', 'request' => $requests[$index]];
        }

        return ['ok' => false, 'message' => 'leave.flash.not_found', 'request' => null];
    }

    public function markLeaveBookSignatureByPlatform(string $id, array $actor, Auth $auth): array
    {
        $writeGuard = $this->writeGuard();

        if (!$this->canManageLeaveBookSignatures($auth)) {
            return ['ok' => false, 'message' => 'leave.flash.not_allowed', 'request' => null];
        }

        $requests = $this->all();

        foreach ($requests as $index => $request) {
            if ((string) ($request['id'] ?? '') !== $id) {
                continue;
            }

            if (!$this->shouldTrackLeaveBookSignature($request)) {
                return ['ok' => false, 'message' => 'leave.flash.signature_not_available', 'request' => $request];
            }

            $signature = $this->normalizeLeaveBookSignatureState($request);

            if ((string) ($signature['status'] ?? 'waiting') !== 'waiting') {
                return ['ok' => false, 'message' => 'leave.flash.signature_already_completed', 'request' => $request];
            }

            $signature['status'] = 'signed';
            $signature['acted_at'] = date('Y-m-d H:i');
            $signature['acted_by'] = (string) ($actor['name'] ?? 'Platform');
            $signature['acted_by_email'] = (string) ($actor['email'] ?? '');
            $signature['source'] = 'platform';
            $request['leave_book_signature'] = $signature;
            $requests[$index] = $request;
            $this->save($requests);

            return [
                'ok' => true,
                'message' => 'leave.flash.signature_signed',
                'request' => $requests[$index],
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.not_found', 'request' => null];
    }

    public function policyForDepartment(string $department): array
    {
        return $this->accessControl?->departmentPolicy($department) ?? [
            'manager_approval_count' => 1,
            'manager_1_email' => '',
            'manager_2_email' => '',
            'hr_email' => '',
        ];
    }

    public function syncDepartmentPolicy(string $department, array $policy): array
    {
        $writeGuard = $this->writeGuard();
        $requests = $this->all();
        $notifications = [];
        $updatedCount = 0;

        foreach ($requests as $index => $request) {
            if ((string) ($request['department'] ?? '') !== $department || $this->currentStage($request) === null) {
                continue;
            }

            $oldStage = $this->currentStage($request);
            $oldRecipient = $oldStage !== null ? $this->assigneeEmailForStage($request, $oldStage) : '';
            $syncedRequest = $this->applyDepartmentPolicyToPendingRequest($request, $policy);
            $newStage = $this->currentStage($syncedRequest);
            $newRecipient = $newStage !== null ? $this->assigneeEmailForStage($syncedRequest, $newStage) : '';
            $currentMailRecipient = $newStage !== null ? (string) ($request['mail_notifications'][$newStage]['to_email'] ?? '') : '';
            $needsNotification = $newStage !== null
                && $newRecipient !== ''
                && ($newStage !== $oldStage || $newRecipient !== $oldRecipient || $currentMailRecipient !== $newRecipient);

            if ($syncedRequest === $request && !$needsNotification) {
                continue;
            }

            if ($needsNotification) {
                $rawToken = $this->newOpaqueToken();
                $syncedRequest['approval_tokens'][$newStage] = $this->hashOpaqueToken($rawToken);
                $syncedRequest['approval_token_expires_at'][$newStage] = $this->tokenExpiresAt();
                $syncedRequest = $this->queueApprovalMail($syncedRequest, $newStage, $rawToken);
                $notifications = array_merge($notifications, $this->notificationsForCurrentStage($syncedRequest));
            }

            $requests[$index] = $syncedRequest;
            $updatedCount++;
        }

        if ($updatedCount > 0) {
            $this->save($requests);
        }

        return [
            'updated' => $updatedCount,
            'notifications' => $notifications,
        ];
    }

    public function migrateUserIdentity(string $oldIdentity, string $newIdentity): array
    {
        if ($oldIdentity === '' || $newIdentity === '' || $oldIdentity === $newIdentity) {
            return ['requests' => 0, 'mail_outbox' => 0];
        }

        $requestGuard = $this->stateStore->beginWrite(self::REQUESTS_STATE_KEY, $this->requestsPath());
        $outboxGuard = $this->stateStore->beginWrite(self::MAIL_OUTBOX_STATE_KEY, $this->mailOutboxPath());
        $requestDocument = $this->stateStore->read(
            self::REQUESTS_STATE_KEY,
            $this->requestsPath(),
            ['version' => self::STORAGE_VERSION, 'requests' => []]
        );
        $requestReferences = 0;
        $requestDocument = IdentityReferenceRewriter::replaceValues(
            $requestDocument,
            $oldIdentity,
            $newIdentity,
            $requestReferences
        );

        if ($requestReferences > 0) {
            $this->stateStore->write(self::REQUESTS_STATE_KEY, $this->requestsPath(), $requestDocument);
        }

        $outboxDocument = $this->stateStore->read(
            self::MAIL_OUTBOX_STATE_KEY,
            $this->mailOutboxPath(),
            ['version' => self::STORAGE_VERSION, 'messages' => []]
        );
        $outboxReferences = 0;
        $outboxDocument = IdentityReferenceRewriter::replaceValues(
            $outboxDocument,
            $oldIdentity,
            $newIdentity,
            $outboxReferences
        );

        if ($outboxReferences > 0) {
            $this->stateStore->write(self::MAIL_OUTBOX_STATE_KEY, $this->mailOutboxPath(), $outboxDocument);
        }

        return ['requests' => $requestReferences, 'mail_outbox' => $outboxReferences];
    }

    public function refreshScheduleCache(): void
    {
        // ShiftStore reads the current state document on every schedule lookup.
    }

    public function entitlementPolicy(): array
    {
        return [
            'bands' => self::ENTITLEMENT_BANDS,
            'age_minimum' => self::AGE_MINIMUM_ENTITLEMENT,
        ];
    }

    public function balanceForUser(array $user, ?string $excludeRequestId = null): array
    {
        $ledger = $this->entitlementLedgerForUser($user);
        $earnedDays = array_sum(array_map(fn (array $entry): int => (int) ($entry['days'] ?? 0), $ledger));
        $openingTotalDays = (float) ($user['leave_opening_total_days'] ?? 0);
        $openingUsedDays = (float) ($user['leave_opening_used_days'] ?? 0);
        $openingRemainingDays = (float) ($user['leave_opening_remaining_days'] ?? 0);
        $earnedDays = max($earnedDays, $openingTotalDays);
        $usedDays = $openingUsedDays;
        $pendingDays = 0.0;

        foreach ($this->all() as $request) {
            if ($excludeRequestId !== null && (string) ($request['id'] ?? '') === $excludeRequestId) {
                continue;
            }

            if (!$this->requestBelongsToUser($request, $user) || ($request['type_key'] ?? '') !== 'leave.type.annual') {
                continue;
            }

            if (($request['status'] ?? '') === 'approved') {
                $usedDays += (float) ($request['total_days'] ?? 0);
                continue;
            }

            if (in_array($request['status'] ?? '', ['waiting_manager_1', 'waiting_manager_2', 'waiting_hr'], true)) {
                $pendingDays += (float) ($request['total_days'] ?? 0);
            }
        }

        return [
            'allowance_days' => $earnedDays,
            'used_days' => $usedDays,
            'pending_days' => $pendingDays,
            'remaining_days' => max(0, $earnedDays - $usedDays - $pendingDays),
            'opening_total_days' => $openingTotalDays,
            'opening_used_days' => $openingUsedDays,
            'opening_remaining_days' => $openingRemainingDays,
            'opening_snapshot_date' => (string) ($user['leave_opening_snapshot_date'] ?? ''),
            'opening_source' => (string) ($user['leave_opening_source'] ?? ''),
        ];
    }

    public function entitlementLedgerForUser(array $user, ?string $untilDate = null): array
    {
        $startedOn = $this->cleanDate((string) ($user['started_on'] ?? ''));

        if ($startedOn === null) {
            return [];
        }

        $startedAt = new DateTimeImmutable($startedOn);
        $until = new DateTimeImmutable($this->cleanDate($untilDate ?? '') ?? date('Y-m-d'));
        $ledger = [];

        for ($serviceYear = 1; $serviceYear <= 80; $serviceYear++) {
            $entitlementDate = $startedAt->modify('+' . $serviceYear . ' years');

            if ($entitlementDate > $until) {
                break;
            }

            $ledger[] = [
                'service_year' => $serviceYear,
                'date' => $entitlementDate->format('Y-m-d'),
                'days' => $this->entitlementDaysForServiceYear($user, $serviceYear, $entitlementDate),
                'rule_key' => $this->entitlementRuleKey($user, $serviceYear, $entitlementDate),
            ];
        }

        return $ledger;
    }

    public function upcomingEntitlementForUser(array $user, int $windowDays = 60): ?array
    {
        $startedOn = $this->cleanDate((string) ($user['started_on'] ?? ''));

        if ($startedOn === null) {
            return null;
        }

        $today = new DateTimeImmutable(date('Y-m-d'));
        $startedAt = new DateTimeImmutable($startedOn);
        $nextEntitlement = $startedAt->setDate(
            (int) $today->format('Y'),
            (int) $startedAt->format('m'),
            (int) $startedAt->format('d')
        );

        if ($nextEntitlement < $today || $nextEntitlement < $startedAt->modify('+1 year')) {
            $nextEntitlement = $nextEntitlement->modify('+1 year');
        }

        $daysUntil = (int) $today->diff($nextEntitlement)->format('%a');

        if ($daysUntil > $windowDays) {
            return null;
        }

        $serviceYear = (int) $startedAt->diff($nextEntitlement)->y;

        return [
            'date' => $nextEntitlement->format('Y-m-d'),
            'days_until' => $daysUntil,
            'earned_days' => $this->entitlementDaysForServiceYear($user, max(1, $serviceYear), $nextEntitlement),
            'service_years' => max(1, $serviceYear),
        ];
    }

    public function calendar(string $view, ?string $focusDate = null, ?array $user = null, ?Auth $auth = null): array
    {
        $view = in_array($view, ['month', 'week', 'day'], true) ? $view : 'month';
        $focus = $this->cleanDate($focusDate ?? '') ?? date('Y-m-d');
        $focusDateTime = new DateTimeImmutable($focus);
        $upcomingEntitlement = is_array($user) ? $this->upcomingEntitlementForUser($user) : null;

        if ($view === 'month') {
            $monthStart = $focusDateTime->modify('first day of this month');
            $monthEnd = $focusDateTime->modify('last day of this month');
            $start = $monthStart->modify('monday this week');
            $end = $monthEnd->modify('sunday this week');
        } elseif ($view === 'week') {
            $start = $focusDateTime->modify('monday this week');
            $end = $focusDateTime->modify('sunday this week');
        } else {
            $start = $focusDateTime;
            $end = $focusDateTime;
        }

        $days = [];
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));

        foreach ($period as $day) {
            $date = $day->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'day' => $day->format('j'),
                'weekday' => $day->format('D'),
                'is_outside_month' => $day->format('m') !== $focusDateTime->format('m'),
                'events' => $this->eventsForDate($date, $user, $upcomingEntitlement, $auth),
            ];
        }

        return [
            'view' => $view,
            'focus' => $focus,
            'title' => $this->titleFor($view, $focusDateTime, $start, $end),
            'days' => $days,
        ];
    }

    private function applyDecision(array $request, string $stage, string $decision, string $actor, string $source, string $decisionNote = ''): array
    {
        if ($stage === self::CANCELLATION_STAGE) {
            return $this->applyCancellationDecision($request, $decision, $actor, $source, $decisionNote);
        }

        $request['approvals'][$stage]['status'] = $decision === 'approve' ? 'approved' : 'rejected';
        $request['approvals'][$stage]['actor'] = $actor;
        $request['approvals'][$stage]['source'] = $source;
        $request['approvals'][$stage]['acted_at'] = date('Y-m-d H:i');
        $request['approvals'][$stage]['reason'] = $decision === 'reject' ? $this->cleanDecisionNote($decisionNote) : '';
        unset($request['approval_tokens'][$stage], $request['approval_token_expires_at'][$stage]);

        if ($decision === 'reject') {
            $request['status'] = 'rejected';
            $request['calendar_state'] = 'blocked';
            $request['rejected_reason'] = $this->cleanDecisionNote($decisionNote);
            $request['rejected_at'] = date('Y-m-d H:i');
            $request['rejected_by'] = $actor;
            $request['rejected_source'] = $source;
            $request['approvals']['calendar']['status'] = 'rejected';
            $request['approvals']['calendar']['actor'] = $actor;
            $request['approvals']['calendar']['source'] = $source;
            $request['approvals']['calendar']['acted_at'] = date('Y-m-d H:i');

            return $request;
        }

        if ($stage === 'manager_1' && $request['manager_count'] === 2) {
            $request['status'] = 'waiting_manager_2';
        } elseif (str_starts_with($stage, 'manager_')) {
            $request['status'] = 'waiting_hr';
        } elseif ($stage === 'hr') {
            $request['status'] = 'approved';
            $request['calendar_state'] = 'confirmed';
            $request['approvals']['calendar']['status'] = 'approved';
            $request['approvals']['calendar']['actor'] = 'System';
            $request['approvals']['calendar']['source'] = 'calendar';
            $request['approvals']['calendar']['acted_at'] = date('Y-m-d H:i');
            $request = $this->queueLeaveBookSignatureMail($request);
        }

        return $this->activateCurrentStageToken($request, true);
    }

    private function applyDepartmentPolicyToPendingRequest(array $request, array $policy): array
    {
        $managerCount = (int) ($policy['manager_approval_count'] ?? 1) === 2 ? 2 : 1;
        $manager1Email = (string) ($policy['manager_1_email'] ?? '');
        $manager2Email = $managerCount === 2 ? (string) ($policy['manager_2_email'] ?? '') : '';
        $hrEmail = (string) ($policy['hr_email'] ?? '');
        $request['manager_count'] = $managerCount;
        $request['approval_policy'] = [
            'manager_1_email' => $manager1Email,
            'manager_2_email' => $manager2Email,
            'hr_email' => $hrEmail,
        ];

        $request['approvals'] = is_array($request['approvals'] ?? null) ? $request['approvals'] : $this->initialApprovals($managerCount, $policy);
        $request['approvals']['manager_1']['assignee'] = $manager1Email;
        $request['approvals']['hr']['assignee'] = $hrEmail;

        if ($managerCount === 1) {
            $request['approvals']['manager_2']['assignee'] = '';

            if (($request['approvals']['manager_2']['status'] ?? '') === 'pending') {
                $request['approvals']['manager_2']['status'] = 'skipped';
            }

            if (($request['status'] ?? '') === 'waiting_manager_2') {
                $request['status'] = 'waiting_hr';
            }

            return $request;
        }

        $request['approvals']['manager_2']['assignee'] = $manager2Email;

        if (($request['status'] ?? '') === 'waiting_manager_1' && ($request['approvals']['manager_2']['status'] ?? '') === 'skipped') {
            $request['approvals']['manager_2']['status'] = 'pending';
        }

        return $request;
    }

    private function decorateForRequester(array $request): array
    {
        $cancellationPending = $this->cancellationPending($request);
        $request['status_key'] = $cancellationPending ? 'leave.status.cancellation_pending' : 'leave.status.' . ($request['status'] ?? '');
        $request['display_status'] = $cancellationPending ? 'cancellation_pending' : (string) ($request['status'] ?? '');
        $request['cancellation_pending'] = $cancellationPending;
        $request['cancellation_request'] = $this->normalizeCancellationRequest($request);
        $request['day_part'] = $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL);
        $request['day_part_key'] = $this->dayPartKey($request);
        $request['total_days'] = (float) ($request['total_days'] ?? 0);
        $request['total_days_label'] = $this->formatDays($request['total_days']);
        $request['history'] = $this->historyForRequest($request);

        return $request;
    }

    private function decorateForLeaveBookSignatureQueue(array $request, array $signature): array
    {
        $notification = is_array($request['mail_notifications']['leave_book_signature'] ?? null)
            ? $request['mail_notifications']['leave_book_signature']
            : [];
        $notificationDueAt = (string) ($signature['notification_due_at'] ?? '');
        $dueAt = (string) ($signature['due_at'] ?? '');
        $now = new DateTimeImmutable('now');
        $notificationDueDate = $this->dateTimeOrNull($notificationDueAt);
        $followupDueDate = $this->dateTimeOrNull($dueAt);
        $state = 'due';

        if ($notificationDueDate !== null && $notificationDueDate > $now) {
            $state = 'planned';
        } elseif ($followupDueDate !== null && $followupDueDate < $now) {
            $state = 'overdue';
        }

        $request['signature_state'] = $state;
        $request['signature_state_key'] = 'leave.signature_queue.state.' . $state;
        $request['signature_sort_at'] = $notificationDueAt !== '' ? $notificationDueAt : ($dueAt !== '' ? $dueAt : (string) ($request['ends_on'] ?? ''));
        $request['leave_book_signature'] = $signature;
        $request['signature_mail_status'] = (string) ($notification['status'] ?? 'not_sent');
        $request['signature_mail_transport'] = (string) ($notification['transport'] ?? '');
        $request['signature_mail_sent_at'] = (string) ($notification['sent_at'] ?? '');
        $request['signature_mail_queued_at'] = (string) ($notification['queued_at'] ?? '');
        $request['day_part'] = $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL);
        $request['day_part_key'] = $this->dayPartKey($request);
        $request['total_days'] = (float) ($request['total_days'] ?? 0);
        $request['total_days_label'] = $this->formatDays($request['total_days']);
        $request['history'] = $this->historyForRequest($request);

        return $request;
    }

    private function canRequesterEditBeforeFirstApproval(array $request, array $user): bool
    {
        if (!$this->requestBelongsToUser($request, $user) || $this->cancellationPending($request)) {
            return false;
        }

        return (string) ($request['status'] ?? '') === 'waiting_manager_1'
            && (string) ($request['approvals']['manager_1']['status'] ?? 'pending') === 'pending';
    }

    private function canRequesterAskCancellationAfterFirstApproval(array $request, array $user): bool
    {
        if (!$this->requestBelongsToUser($request, $user) || $this->cancellationPending($request)) {
            return false;
        }

        return $this->firstManagerApproved($request)
            && in_array((string) ($request['status'] ?? ''), ['waiting_manager_2', 'waiting_hr', 'approved'], true);
    }

    private function requestBelongsToUser(array $request, array $user): bool
    {
        $requesterId = $this->requesterPersonnelId($request);
        $userId = $this->personnelIdForUser($user);

        if ($requesterId !== '' && $userId !== '') {
            return hash_equals($requesterId, $userId);
        }

        $requesterEmail = strtolower(trim((string) ($request['requester_email'] ?? '')));
        $userEmail = strtolower(trim((string) ($user['email'] ?? '')));

        if ($requesterEmail !== '' && $userEmail !== '' && $requesterEmail === $userEmail) {
            return true;
        }

        return false;
    }

    private function requesterPersonnelId(array $request): string
    {
        $requesterId = trim((string) ($request['requester_id'] ?? ''));

        if ($requesterId !== '') {
            return $requesterId;
        }

        $requesterEmail = trim((string) ($request['requester_email'] ?? ''));

        if ($requesterEmail !== '') {
            $profile = $this->userProfiles->find($requesterEmail);
            $personnelId = trim((string) ($profile['personnel_id'] ?? ''));

            if ($personnelId !== '') {
                return $personnelId;
            }
        }

        $requesterName = trim((string) ($request['requester'] ?? ''));

        if ($requesterName === '') {
            return '';
        }

        $matches = array_values(array_filter(
            $this->userProfiles->users(),
            static fn (array $profile): bool => trim((string) ($profile['name'] ?? '')) === $requesterName
        ));

        return count($matches) === 1 ? trim((string) ($matches[0]['personnel_id'] ?? '')) : '';
    }

    private function requesterProfileForRequest(array $request): array
    {
        foreach (['requester_id', 'requester_email'] as $identityKey) {
            $identity = trim((string) ($request[$identityKey] ?? ''));

            if ($identity === '') {
                continue;
            }

            $profile = $this->userProfiles->find($identity);

            if ($profile !== null) {
                return $profile;
            }
        }

        return [
            'personnel_id' => (string) ($request['requester_id'] ?? ''),
            'email' => (string) ($request['requester_email'] ?? ''),
            'name' => (string) ($request['requester'] ?? ''),
            'department' => (string) ($request['department'] ?? ''),
            'location' => (string) ($request['requester_location'] ?? ''),
        ];
    }

    private function personnelIdForUser(array $user): string
    {
        $personnelId = trim((string) ($user['personnel_id'] ?? ''));

        if ($personnelId !== '') {
            return $personnelId;
        }

        $identifier = trim((string) ($user['profile_key'] ?? $user['email'] ?? ''));
        $profile = $identifier !== '' ? $this->userProfiles->find($identifier) : null;

        return trim((string) ($profile['personnel_id'] ?? ''));
    }

    private function firstManagerApproved(array $request): bool
    {
        return (string) ($request['approvals']['manager_1']['status'] ?? '') === 'approved';
    }

    private function cancellationPending(array $request): bool
    {
        return (string) ($this->normalizeCancellationRequest($request)['status'] ?? 'none') === 'pending';
    }

    private function normalizeCancellationRequest(array $request): array
    {
        $cancellation = is_array($request['cancellation_request'] ?? null) ? $request['cancellation_request'] : [];
        $status = (string) ($cancellation['status'] ?? 'none');
        $status = in_array($status, ['none', 'pending', 'approved', 'rejected'], true) ? $status : 'none';

        return array_merge([
            'status' => $status,
            'requested_at' => null,
            'requested_by' => null,
            'requested_by_email' => null,
            'approver_email' => null,
            'original_status' => (string) ($request['status'] ?? ''),
            'original_calendar_state' => (string) ($request['calendar_state'] ?? ''),
            'acted_at' => null,
            'acted_by' => null,
            'source' => null,
            'reason' => '',
        ], $cancellation, [
            'status' => $status,
            'reason' => $this->cleanDecisionNote((string) ($cancellation['reason'] ?? '')),
        ]);
    }

    private function currentStage(array $request): ?string
    {
        if ($this->cancellationPending($request)) {
            return self::CANCELLATION_STAGE;
        }

        return match ($request['status']) {
            'waiting_manager_1' => 'manager_1',
            'waiting_manager_2' => 'manager_2',
            'waiting_hr' => 'hr',
            default => null,
        };
    }

    private function canActOnStage(string $stage, Auth $auth, array $request): bool
    {
        if ($auth->can('admin.company.manage')) {
            return true;
        }

        $user = $auth->user();

        if (!is_array($user) || !LocationScope::canView($user, $this->requesterProfileForRequest($request))) {
            return false;
        }

        $userEmail = $user['email'] ?? '';
        $policy = $request['approval_policy'] ?? $this->accessControl?->departmentPolicy($request['department'] ?? '') ?? [];

        if ($stage === self::CANCELLATION_STAGE) {
            $managerEmail = $policy['manager_1_email'] ?? '';

            return $auth->can('leave.request.approve.department') && ($managerEmail === '' || $userEmail === $managerEmail);
        }

        if ($stage === 'hr') {
            if (!$auth->can('leave.request.manage.hr')) {
                return false;
            }

            if ($this->isRegionalHrAssistant($user)) {
                return true;
            }

            $hrEmail = $policy['hr_email'] ?? '';

            return $hrEmail === '' || $userEmail === $hrEmail;
        }

        $managerEmail = $policy[$stage . '_email'] ?? '';

        return $auth->can('leave.request.approve.department') && ($managerEmail === '' || $userEmail === $managerEmail);
    }

    private function isRegionalHrAssistant(array $user): bool
    {
        $roles = is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [];
        $regionalRoles = array_intersect($roles, [
            'hr_assistant_antalya',
            'hr_assistant_bursa',
        ]);

        return count($regionalRoles) === 1
            && !in_array('hr', $roles, true)
            && !in_array('hr_assistant', $roles, true);
    }

    private function canCancelRequest(Auth $auth, array $request): bool
    {
        if ($this->cancellationPending($request)) {
            return false;
        }

        if (!in_array((string) ($request['status'] ?? ''), ['waiting_manager_1', 'waiting_manager_2', 'waiting_hr', 'approved'], true)) {
            return false;
        }

        return $auth->can('admin.company.manage') || $auth->can('leave.request.cancel');
    }

    private function canManageLeaveBookSignatures(Auth $auth): bool
    {
        return $auth->can('admin.company.manage') || $auth->can('leave.request.manage.hr');
    }

    private function applyCancellationDecision(array $request, string $decision, string $actor, string $source, string $decisionNote = ''): array
    {
        $cancellation = $this->normalizeCancellationRequest($request);
        $cancellation['status'] = $decision === 'approve' ? 'approved' : 'rejected';
        $cancellation['acted_at'] = date('Y-m-d H:i');
        $cancellation['acted_by'] = $actor;
        $cancellation['source'] = $source;
        $cancellation['reason'] = $decision === 'reject' ? $this->cleanDecisionNote($decisionNote) : '';
        $request['cancellation_request'] = $cancellation;

        if ($decision === 'approve') {
            return $this->cancelRequest($request, $actor, $source);
        }

        unset($request['approval_tokens'][self::CANCELLATION_STAGE], $request['approval_token_expires_at'][self::CANCELLATION_STAGE]);

        return $request;
    }

    private function cancelRequest(array $request, string $actor, string $source): array
    {
        $request['status'] = 'cancelled';
        $request['calendar_state'] = 'blocked';
        $request['cancelled_at'] = date('Y-m-d H:i');
        $request['cancelled_by'] = $actor;
        $request['cancelled_source'] = $source;
        unset($request['approval_tokens'][self::CANCELLATION_STAGE], $request['approval_token_expires_at'][self::CANCELLATION_STAGE]);

        foreach (['manager_1', 'manager_2', 'hr'] as $stage) {
            if (($request['approvals'][$stage]['status'] ?? '') === 'pending') {
                $request['approvals'][$stage]['status'] = 'cancelled';
                $request['approvals'][$stage]['actor'] = $actor;
                $request['approvals'][$stage]['source'] = $source;
                $request['approvals'][$stage]['acted_at'] = date('Y-m-d H:i');
            }
        }

        $request['approvals']['calendar']['status'] = 'cancelled';
        $request['approvals']['calendar']['actor'] = $actor;
        $request['approvals']['calendar']['source'] = $source;
        $request['approvals']['calendar']['acted_at'] = date('Y-m-d H:i');

        return $request;
    }

    private function eventsForDate(string $date, ?array $user = null, ?array $upcomingEntitlement = null, ?Auth $auth = null): array
    {
        $events = [];
        foreach ($this->all() as $request) {
            if ($date < $request['starts_on'] || $date > $request['ends_on']) {
                continue;
            }

            if (is_array($user) && !$this->canUserViewRequest($request, $user, $auth)) {
                continue;
            }

            $stage = $this->currentStage($request);
            $canAct = $stage !== null && $auth !== null && $this->canActOnStage($stage, $auth, $request);

            $events[] = [
                'id' => $request['id'],
                'requester' => $request['requester'],
                'department' => $request['department'] ?? '',
                'type_key' => $request['type_key'] ?? 'leave.type.annual',
                'starts_on' => $request['starts_on'],
                'ends_on' => $request['ends_on'],
                'day_part' => $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL),
                'day_part_key' => $this->dayPartKey($request),
                'total_days' => (float) ($request['total_days'] ?? 0),
                'total_days_label' => $this->formatDays($request['total_days'] ?? 0),
                'status' => $this->cancellationPending($request) ? 'cancellation_pending' : $request['status'],
                'status_key' => $this->cancellationPending($request) ? 'leave.status.cancellation_pending' : 'leave.status.' . $request['status'],
                'cancellation_pending' => $this->cancellationPending($request),
                'calendar_state' => $request['calendar_state'],
                'current_stage' => $stage,
                'can_act' => $canAct,
                'decision_url' => $canAct ? '/leave/requests/' . rawurlencode((string) ($request['id'] ?? '')) . '/decision' : '',
                'entitlement_hint' => is_array($user) && $this->requestBelongsToUser($request, $user) ? $upcomingEntitlement : null,
            ];
        }

        return $events;
    }

    private function canUserViewRequest(array $request, array $user, ?Auth $auth = null): bool
    {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];

        if (in_array('*', $permissions, true) || in_array('admin.company.manage', $permissions, true)) {
            return true;
        }

        if ($this->requestBelongsToUser($request, $user)) {
            return true;
        }

        if (!LocationScope::canView($user, $this->requesterProfileForRequest($request))) {
            return false;
        }

        if (in_array('leave.request.manage.hr', $permissions, true)) {
            return true;
        }

        $userEmail = (string) ($user['email'] ?? '');
        $requestDepartment = (string) ($request['department'] ?? '');
        $userDepartment = (string) ($user['department'] ?? '');
        $policy = is_array($request['approval_policy'] ?? null)
            ? $request['approval_policy']
            : ($this->accessControl?->departmentPolicy($requestDepartment) ?? []);

        if (in_array('leave.request.approve.department', $permissions, true)) {
            if ($requestDepartment !== '' && $userDepartment !== '' && $requestDepartment === $userDepartment) {
                return true;
            }

            foreach (['manager_1_email', 'manager_2_email'] as $managerKey) {
                if ($userEmail !== '' && (string) ($policy[$managerKey] ?? '') === $userEmail) {
                    return true;
                }
            }
        }

        return $this->workforceGroupForDepartment($requestDepartment) === $this->workforceGroupForDepartment($userDepartment);
    }

    private function historyForRequest(array $request): array
    {
        $history = [];
        $add = static function (string $at, string $labelKey, string $actor = '', string $source = '', string $note = '', string $stageKey = '') use (&$history): void {
            $at = trim($at);

            if ($at === '') {
                return;
            }

            $history[] = [
                'at' => $at,
                'label_key' => $labelKey,
                'actor' => trim($actor),
                'source' => trim($source),
                'note' => trim($note),
                'stage_key' => $stageKey,
            ];
        };

        $add((string) ($request['created_at'] ?? ''), 'leave.history.created', (string) ($request['requester'] ?? ''), 'requester');
        $add((string) ($request['updated_at'] ?? ''), 'leave.history.updated', (string) ($request['updated_by'] ?? ''), (string) ($request['updated_source'] ?? ''));

        foreach (['manager_1', 'manager_2', 'hr', 'calendar'] as $stageKey) {
            $stage = is_array($request['approvals'][$stageKey] ?? null) ? $request['approvals'][$stageKey] : [];
            $status = (string) ($stage['status'] ?? '');

            if (!in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
                continue;
            }

            $labelKey = match ($status) {
                'approved' => 'leave.history.approved',
                'rejected' => 'leave.history.rejected',
                default => 'leave.history.cancelled',
            };

            $add(
                (string) ($stage['acted_at'] ?? ''),
                $labelKey,
                (string) ($stage['actor'] ?? ''),
                (string) ($stage['source'] ?? ''),
                (string) ($stage['reason'] ?? ''),
                'leave.stage.' . $stageKey
            );
        }

        $cancellation = $this->normalizeCancellationRequest($request);
        $add((string) ($cancellation['requested_at'] ?? ''), 'leave.history.cancellation_requested', (string) ($cancellation['requested_by'] ?? ''), 'requester');

        if (in_array((string) ($cancellation['status'] ?? ''), ['approved', 'rejected'], true)) {
            $add(
                (string) ($cancellation['acted_at'] ?? ''),
                (string) ($cancellation['status'] ?? '') === 'approved' ? 'leave.history.cancellation_approved' : 'leave.history.cancellation_rejected',
                (string) ($cancellation['acted_by'] ?? ''),
                (string) ($cancellation['source'] ?? ''),
                (string) ($cancellation['reason'] ?? ''),
                'leave.stage.cancellation_manager_1'
            );
        }

        $add((string) ($request['cancelled_at'] ?? ''), 'leave.history.cancelled', (string) ($request['cancelled_by'] ?? ''), (string) ($request['cancelled_source'] ?? ''));

        $signature = $this->normalizeLeaveBookSignatureState($request);
        $add((string) ($signature['required_at'] ?? ''), 'leave.history.signature_required', (string) ($request['requester'] ?? ''), 'system');

        if (in_array((string) ($signature['status'] ?? ''), ['signed', 'not_signed'], true)) {
            $add(
                (string) ($signature['acted_at'] ?? ''),
                (string) ($signature['status'] ?? '') === 'signed' ? 'leave.history.signature_signed' : 'leave.history.signature_not_signed',
                (string) ($signature['acted_by'] ?? ''),
                (string) ($signature['source'] ?? '')
            );
        }

        usort($history, fn (array $a, array $b): int => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));

        return $history;
    }

    private function workforceGroupForDepartment(string $department): string
    {
        $normalized = $this->normalizeSearchText($department);

        if (
            str_contains($normalized, 'mavi')
            || str_contains($normalized, 'blue')
            || str_contains($normalized, 'gazileri')
            || preg_match('/(^|\\s|_)bc($|\\s|_)/', $normalized) === 1
        ) {
            return 'blue';
        }

        if (str_contains($normalized, 'system') || str_contains($normalized, 'sistem')) {
            return 'system';
        }

        return 'office';
    }

    private function titleFor(string $view, DateTimeImmutable $focus, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return match ($view) {
            'week' => $start->format('d M') . ' - ' . $end->format('d M Y'),
            'day' => $focus->format('d M Y'),
            default => $focus->format('F Y'),
        };
    }

    private function completedServiceYears(array $user, ?DateTimeImmutable $asOf = null): int
    {
        $startedOn = $this->cleanDate((string) ($user['started_on'] ?? ''));

        if ($startedOn === null) {
            return 0;
        }

        $startedAt = new DateTimeImmutable($startedOn);
        $asOf ??= new DateTimeImmutable(date('Y-m-d'));

        return max(0, (int) $startedAt->diff($asOf)->y);
    }

    private function entitlementDaysForServiceYear(array $user, int $serviceYear, DateTimeImmutable $entitlementDate): int
    {
        if ($serviceYear < 1) {
            return 0;
        }

        $band = $this->entitlementBandForServiceYear($serviceYear);
        $days = (int) ($band['days'] ?? 0);

        $age = $this->ageAt($user, $entitlementDate);

        if ($age !== null && ($age <= 18 || $age >= 50)) {
            $days = max($days, (int) self::AGE_MINIMUM_ENTITLEMENT['days']);
        }

        return $days;
    }

    private function entitlementRuleKey(array $user, int $serviceYear, DateTimeImmutable $entitlementDate): string
    {
        $age = $this->ageAt($user, $entitlementDate);

        if ($age !== null && ($age <= 18 || $age >= 50)) {
            return (string) self::AGE_MINIMUM_ENTITLEMENT['label_key'];
        }

        $band = $this->entitlementBandForServiceYear($serviceYear);

        return (string) ($band['label_key'] ?? 'leave.entitlement.rule.fifteen_plus');
    }

    private function entitlementBandForServiceYear(int $serviceYear): array
    {
        foreach (self::ENTITLEMENT_BANDS as $band) {
            $minimum = (int) ($band['min_year'] ?? 1);
            $maximum = $band['max_year'] ?? null;

            if ($serviceYear >= $minimum && ($maximum === null || $serviceYear <= (int) $maximum)) {
                return $band;
            }
        }

        return self::ENTITLEMENT_BANDS[array_key_last(self::ENTITLEMENT_BANDS)];
    }

    private function ageAt(array $user, DateTimeImmutable $date): ?int
    {
        $birthDate = $this->cleanDate((string) ($user['birth_date'] ?? ''));

        if ($birthDate === null) {
            return null;
        }

        return (int) (new DateTimeImmutable($birthDate))->diff($date)->y;
    }

    private function activateCurrentStageToken(array $request, bool $queueMail): array
    {
        $stage = $this->currentStage($request);

        if ($stage === null) {
            return $request;
        }

        $rawToken = $this->newOpaqueToken();
        $request['approval_tokens'][$stage] = $this->hashOpaqueToken($rawToken);
        $request['approval_token_expires_at'][$stage] = $this->tokenExpiresAt();

        return $queueMail ? $this->queueApprovalMail($request, $stage, $rawToken) : $request;
    }

    private function tokenExpired(array $request, string $stage): bool
    {
        $expiresAt = (string) ($request['approval_token_expires_at'][$stage] ?? '');

        if ($expiresAt === '') {
            return true;
        }

        return new DateTimeImmutable($expiresAt) < new DateTimeImmutable('now');
    }

    private function tokenExpiresAt(): string
    {
        return (new DateTimeImmutable('now'))->modify('+' . self::TOKEN_TTL_HOURS . ' hours')->format('Y-m-d H:i');
    }

    private function newOpaqueToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hashOpaqueToken(string $token): string
    {
        return 'sha256:' . hash('sha256', $token);
    }

    private function validOpaqueToken(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{32,128}\z/', $token) === 1;
    }

    private function opaqueTokenMatches(string $storedToken, string $providedToken): bool
    {
        if (!$this->validOpaqueToken($providedToken) || $storedToken === '') {
            return false;
        }

        if (str_starts_with($storedToken, 'sha256:')) {
            return hash_equals($storedToken, $this->hashOpaqueToken($providedToken));
        }

        return hash_equals($storedToken, $providedToken);
    }

    private function tokenResultRequest(array $request): array
    {
        unset(
            $request['approval_tokens'],
            $request['approval_token_expires_at'],
            $request['mail_notifications'],
            $request['approval_policy']
        );

        if (is_array($request['leave_book_signature'] ?? null)) {
            unset(
                $request['leave_book_signature']['followup_token'],
                $request['leave_book_signature']['followup_token_expires_at']
            );
        }

        return $request;
    }

    private function queueApprovalMail(array $request, string $stage, string $rawToken): array
    {
        $recipient = $this->assigneeEmailForStage($request, $stage);

        if ($recipient === '' || !$this->assigneeCanViewRequest($request, $stage)) {
            return $request;
        }

        $isCancellationStage = $stage === self::CANCELLATION_STAGE;
        $approvePath = '/leave/mail-approval/' . $rawToken . '/approve';
        $rejectPath = '/leave/mail-approval/' . $rawToken . '/reject';
        $entry = [
            'id' => 'MAIL-' . bin2hex(random_bytes(6)),
            'type' => $isCancellationStage ? 'leave_cancellation_approval' : 'leave_approval',
            'request_id' => $request['id'],
            'stage' => $stage,
            'to_email' => $recipient,
            'subject' => ($isCancellationStage ? 'Izin iptal onayi: ' : 'Izin onayi: ') . $request['requester'] . ' / ' . $this->requestDateRange($request),
            'body' => implode("\n", [
                $isCancellationStage ? 'Izin iptal talebi onayinizi bekliyor.' : 'Izin talebi onayinizi bekliyor.',
                'Talep: ' . $request['id'],
                'Talep sahibi: ' . $request['requester'],
                'Tarih: ' . $this->requestDateRange($request),
                'Gun bolumu: ' . $this->dayPartMailLabel($request),
                'Gun: ' . $this->requestTotalDaysLabel($request),
                $isCancellationStage
                    ? 'Iptal onayi veya red karari icin e-postadaki butonlari kullanin.'
                    : 'Onay veya red karari icin e-postadaki butonlari kullanin.',
                'Token gecerlilik: ' . self::TOKEN_TTL_HOURS . ' saat, son tarih ' . ($request['approval_token_expires_at'][$stage] ?? ''),
            ]),
            'body_html' => $this->approvalMailHtml($request, $stage, $this->absoluteUrl($approvePath), $this->absoluteUrl($rejectPath)),
            'approve_url' => $this->absoluteUrl($approvePath),
            'reject_url' => $this->absoluteUrl($rejectPath),
            'token_expires_at' => $request['approval_token_expires_at'][$stage] ?? null,
            'created_at' => date('Y-m-d H:i'),
        ];
        $entry = $this->deliverMailEntry($entry);
        $request['mail_notifications'][$stage] = [
            'to_email' => $recipient,
            'queued_at' => $entry['created_at'],
            'sent_at' => $entry['sent_at'],
            'status' => $entry['status'],
            'transport' => $entry['transport'],
            'error' => (string) ($entry['error'] ?? ''),
            'token_expires_at' => $entry['token_expires_at'],
            'outbox_id' => $entry['id'],
        ];

        return $request;
    }

    private function queueRequesterReceiptMail(array $request, array $user): array
    {
        $recipient = (string) ($user['email'] ?? ($request['requester_email'] ?? ''));

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return $request;
        }

        $entry = [
            'id' => 'MAIL-' . bin2hex(random_bytes(6)),
            'type' => 'leave_request_received',
            'request_id' => $request['id'],
            'stage' => 'requester_receipt',
            'to_email' => $recipient,
            'subject' => 'Izin talebiniz alindi: ' . $request['id'],
            'body' => implode("\n", [
                'Izin talebiniz basariyla alinmistir.',
                'Talebiniz onay akisina alinmistir.',
                'Talep: ' . $request['id'],
                'Talep sahibi: ' . $request['requester'],
                'Tarih: ' . $this->requestDateRange($request),
                'Gun bolumu: ' . $this->dayPartMailLabel($request),
                'Gun: ' . $this->requestTotalDaysLabel($request),
            ]),
            'body_html' => $this->requestReceiptMailHtml($request),
            'portal_url' => $this->absoluteUrl('/module/leave'),
            'token_expires_at' => null,
            'created_at' => date('Y-m-d H:i'),
        ];
        $entry = $this->deliverMailEntry($entry);
        $request['mail_notifications']['requester_receipt'] = [
            'to_email' => $recipient,
            'queued_at' => $entry['created_at'],
            'sent_at' => $entry['sent_at'],
            'status' => $entry['status'],
            'transport' => $entry['transport'],
            'error' => (string) ($entry['error'] ?? ''),
            'outbox_id' => $entry['id'],
        ];

        return $request;
    }

    private function queueLeaveBookSignatureMail(array $request): array
    {
        if (($request['type_key'] ?? '') !== 'leave.type.annual') {
            return $request;
        }

        $signature = $this->normalizeLeaveBookSignatureState($request);
        $signature['status'] = 'waiting';
        $signature['notification_due_at'] = $this->leaveBookSignatureNotificationDueAt($request);
        $signature['followup_recipients'] = $this->signatureFollowupRecipients($request);
        $request['leave_book_signature'] = $signature;

        if ($this->signatureNotificationQueued($request)) {
            return $request;
        }

        $notificationDueAt = $this->dateTimeOrNull((string) ($signature['notification_due_at'] ?? ''));

        if ($notificationDueAt !== null && $notificationDueAt > new DateTimeImmutable('now')) {
            return $request;
        }

        return $this->deliverLeaveBookSignatureMail($request);
    }

    private function deliverLeaveBookSignatureMail(array $request, ?DateTimeImmutable $now = null): array
    {
        $recipient = (string) ($request['requester_email'] ?? '');

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || $this->signatureNotificationQueued($request)) {
            return $request;
        }

        $createdAt = ($now ?? new DateTimeImmutable('now'))->format('Y-m-d H:i');
        $entry = [
            'id' => 'MAIL-' . bin2hex(random_bytes(6)),
            'type' => 'leave_book_signature_required',
            'request_id' => $request['id'],
            'stage' => 'leave_book_signature',
            'to_email' => $recipient,
            'subject' => 'Yillik izin defteri imzasi: ' . $request['id'],
            'body' => implode("\n", [
                'Yillik izin talebiniz IK tarafindan onaylanmistir.',
                'Bu bilgilendirme izin donusunuzun ilk mesai gunune gore gonderilmistir.',
                'Yillik izin defterini imzalamak icin IK asistanina basvurmaniz gerekmektedir.',
                'Yillik izin defterini en gec 2 gun icinde imzalamaniz gerekmektedir.',
                'Talep: ' . $request['id'],
                'Talep sahibi: ' . $request['requester'],
                'Tarih: ' . $this->requestDateRange($request),
                'Gun bolumu: ' . $this->dayPartMailLabel($request),
                'Gun: ' . $this->requestTotalDaysLabel($request),
            ]),
            'body_html' => $this->leaveBookSignatureMailHtml($request),
            'portal_url' => $this->absoluteUrl('/module/leave'),
            'token_expires_at' => null,
            'created_at' => $createdAt,
        ];
        $entry = $this->deliverMailEntry($entry);
        $request['mail_notifications']['leave_book_signature'] = [
            'to_email' => $recipient,
            'queued_at' => $entry['created_at'],
            'sent_at' => $entry['sent_at'],
            'status' => $entry['status'],
            'transport' => $entry['transport'],
            'error' => (string) ($entry['error'] ?? ''),
            'outbox_id' => $entry['id'],
        ];
        $signature = $this->normalizeLeaveBookSignatureState($request);
        $signature['status'] = 'waiting';
        $signature['required_at'] = (string) ($entry['sent_at'] ?? '') !== '' ? (string) $entry['sent_at'] : $entry['created_at'];
        $signature['notification_due_at'] = $signature['notification_due_at'] ?? $this->leaveBookSignatureNotificationDueAt($request);
        $signature['due_at'] = $this->addDays($signature['required_at'], $this->signatureFollowupDays());
        $signature['followup_due_at'] = $signature['due_at'];
        $signature['followup_recipients'] = $this->signatureFollowupRecipients($request);
        $request['leave_book_signature'] = $signature;

        return $request;
    }

    private function queueLeaveBookSignatureFollowupMail(array $request, array $recipients, DateTimeImmutable $now): array
    {
        $signature = $this->normalizeLeaveBookSignatureState($request);

        $rawToken = $this->newOpaqueToken();
        $signature['followup_token'] = $this->hashOpaqueToken($rawToken);
        $signature['followup_token_expires_at'] = $now
            ->modify('+' . $this->signatureTokenTtlDays() . ' days')
            ->format('Y-m-d H:i');

        $createdAt = date('Y-m-d H:i');
        $signedUrl = $this->absoluteUrl('/leave/book-signature/' . $rawToken . '/signed');
        $notSignedUrl = $this->absoluteUrl('/leave/book-signature/' . $rawToken . '/not-signed');
        $entries = [];

        foreach ($recipients as $recipient) {
            $entry = [
                'id' => 'MAIL-' . bin2hex(random_bytes(6)),
                'type' => 'leave_book_signature_followup',
                'request_id' => $request['id'],
                'stage' => 'leave_book_signature_followup',
                'to_email' => $recipient,
                'subject' => 'Yillik izin defteri imza kontrolu: ' . $request['requester'],
                'body' => implode("\n", [
                    'Bu calisan yillik izin defterini imzaladi mi?',
                    'Talep: ' . $request['id'],
                    'Talep sahibi: ' . $request['requester'],
                    'Tarih: ' . $this->requestDateRange($request),
                    'Gun bolumu: ' . $this->dayPartMailLabel($request),
                    'Gun: ' . $this->requestTotalDaysLabel($request),
                    'Lutfen e-postadaki Imzalandi veya Imzalanmadi butonlarindan birini kullanin.',
                ]),
                'body_html' => $this->leaveBookSignatureFollowupMailHtml($request, $signedUrl, $notSignedUrl),
                'signed_url' => $signedUrl,
                'not_signed_url' => $notSignedUrl,
                'token_expires_at' => $signature['followup_token_expires_at'],
                'created_at' => $createdAt,
            ];
            $entries[] = $this->deliverMailEntry($entry);
        }

        $signature['followup_queued_at'] = $createdAt;
        $signature['followup_sent_at'] = $this->allMailEntriesSent($entries) ? $createdAt : null;
        $signature['followup_recipients'] = $recipients;
        $signature['followup_outbox_ids'] = array_map(static fn (array $entry): string => (string) ($entry['id'] ?? ''), $entries);
        $request['leave_book_signature'] = $signature;
        $request['mail_notifications']['leave_book_signature_followup'] = [
            'to_email' => implode(', ', $recipients),
            'recipients' => $recipients,
            'queued_at' => $createdAt,
            'sent_at' => $signature['followup_sent_at'],
            'status' => $this->mailBatchStatus($entries),
            'transport' => $this->mailBatchTransports($entries),
            'error' => $this->mailBatchErrors($entries),
            'token_expires_at' => $signature['followup_token_expires_at'],
            'outbox_ids' => $signature['followup_outbox_ids'],
        ];

        return $request;
    }

    private function deliverMailEntry(array $entry): array
    {
        $mailResult = $this->approvalMailer?->send($entry) ?? [
            'ok' => true,
            'status' => 'queued',
            'transport' => 'outbox',
            'error' => '',
        ];
        $entry['status'] = (string) ($mailResult['status'] ?? 'queued');
        $entry['transport'] = (string) ($mailResult['transport'] ?? 'outbox');
        $entry['sent_at'] = $entry['status'] === 'sent' ? date('Y-m-d H:i') : null;

        if ((string) ($mailResult['error'] ?? '') !== '') {
            $entry['error'] = (string) $mailResult['error'];
        }

        $this->appendMailOutbox($this->redactedMailEntry($entry));

        return $entry;
    }

    private function approvalMailHtml(array $request, string $stage, string $approveUrl, string $rejectUrl): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $expiresAt = (string) ($request['approval_token_expires_at'][$stage] ?? '');
        $isCancellationStage = $stage === self::CANCELLATION_STAGE;
        $heading = $isCancellationStage ? 'Izin iptal talebi onayinizi bekliyor' : 'Izin talebi onayinizi bekliyor';
        $intro = $isCancellationStage
            ? 'Personel bu izin talebinin iptalini istedi. Butonlar ' . self::TOKEN_TTL_HOURS . ' saat gecerlidir.'
            : 'Asagidaki izin talebi icin karar verebilirsiniz. Butonlar ' . self::TOKEN_TTL_HOURS . ' saat gecerlidir.';
        $approveLabel = $isCancellationStage ? 'Iptali onayla' : 'Onayla';

        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;background:#f5f7f4;font-family:Arial,Helvetica,sans-serif;color:#1f2428;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7f4;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dfe4dc;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="padding:22px 24px;background:#1f2428;color:#ffffff;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">MyTakii Intranet</div>'
            . '<h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;">' . $escape($heading) . '</h1>'
            . '</td></tr>'
            . '<tr><td style="padding:22px 24px;">'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">' . $escape($intro) . '</p>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 18px;">'
            . $this->approvalMailRow('Talep', $request['id'] ?? '')
            . $this->approvalMailRow('Talep sahibi', $request['requester'] ?? '')
            . $this->approvalMailRow('Tarih', $this->requestDateRange($request))
            . $this->approvalMailRow('Gun bolumu', $this->dayPartMailLabel($request))
            . $this->approvalMailRow('Gun', $this->requestTotalDaysLabel($request))
            . $this->approvalMailRow('Gecerlilik', $expiresAt !== '' ? $expiresAt : self::TOKEN_TTL_HOURS . ' saat')
            . '</table>'
            . '<table role="presentation" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="padding:0 10px 10px 0;"><a href="' . $escape($approveUrl) . '" style="display:inline-block;background:#24613b;color:#ffffff;text-decoration:none;font-weight:700;border-radius:7px;padding:12px 18px;">' . $escape($approveLabel) . '</a></td>'
            . '<td style="padding:0 0 10px 0;"><a href="' . $escape($rejectUrl) . '" style="display:inline-block;background:#8a341f;color:#ffffff;text-decoration:none;font-weight:700;border-radius:7px;padding:12px 18px;">Reddet</a></td>'
            . '</tr></table>'
            . '<p style="margin:12px 0 0;font-size:12px;line-height:1.5;color:#66716a;">HTML desteklemeyen e-posta uygulamalarinda MyTakii Intranet uzerinden panel onayi kullanabilirsiniz.</p>'
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    private function requestReceiptMailHtml(array $request): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $portalUrl = $this->absoluteUrl('/module/leave');

        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;background:#f5f7f4;font-family:Arial,Helvetica,sans-serif;color:#1f2428;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7f4;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dfe4dc;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="padding:22px 24px;background:#24613b;color:#ffffff;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">MyTakii Intranet</div>'
            . '<h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;">Izin talebiniz basariyla alinmistir</h1>'
            . '</td></tr>'
            . '<tr><td style="padding:22px 24px;">'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Talebiniz onay akisina alinmistir. Sureci izin merkezinden takip edebilirsiniz.</p>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 18px;">'
            . $this->approvalMailRow('Talep', $request['id'] ?? '')
            . $this->approvalMailRow('Talep sahibi', $request['requester'] ?? '')
            . $this->approvalMailRow('Tarih', $this->requestDateRange($request))
            . $this->approvalMailRow('Gun bolumu', $this->dayPartMailLabel($request))
            . $this->approvalMailRow('Gun', $this->requestTotalDaysLabel($request))
            . $this->approvalMailRow('Durum', 'Onay bekliyor')
            . '</table>'
            . '<a href="' . $escape($portalUrl) . '" style="display:inline-block;background:#1f2428;color:#ffffff;text-decoration:none;font-weight:700;border-radius:7px;padding:12px 18px;">Izin merkezini ac</a>'
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    private function leaveBookSignatureMailHtml(array $request): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $hrContact = (string) ($request['approval_policy']['hr_email'] ?? '');
        $hrLine = $hrContact !== ''
            ? 'IK asistani / IK birimi ile iletisime gecin: ' . $hrContact
            : 'IK asistani / IK birimi ile iletisime gecin.';

        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;background:#f5f7f4;font-family:Arial,Helvetica,sans-serif;color:#1f2428;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7f4;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dfe4dc;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="padding:22px 24px;background:#1f2428;color:#ffffff;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">MyTakii Intranet</div>'
            . '<h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;">Yillik izin defteri imzasi gerekiyor</h1>'
            . '</td></tr>'
            . '<tr><td style="padding:22px 24px;">'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Yillik izin talebiniz IK tarafindan onaylandi. Bu bilgilendirme izin donusunuzun ilk mesai gunune gore gonderilmistir. Izin surecinizin tamamlanmasi icin yillik izin defterini imzalamaniz gerekmektedir.</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;font-weight:700;color:#8a341f;">Yillik izin defterini en gec 2 gun icinde imzalamaniz gerekmektedir.</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;font-weight:700;color:#24613b;">' . $escape($hrLine) . '</p>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 18px;">'
            . $this->approvalMailRow('Talep', $request['id'] ?? '')
            . $this->approvalMailRow('Talep sahibi', $request['requester'] ?? '')
            . $this->approvalMailRow('Tarih', $this->requestDateRange($request))
            . $this->approvalMailRow('Gun bolumu', $this->dayPartMailLabel($request))
            . $this->approvalMailRow('Gun', $this->requestTotalDaysLabel($request))
            . $this->approvalMailRow('Durum', 'IK onaylandi / defter imzasi bekliyor')
            . '</table>'
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    private function leaveBookSignatureFollowupMailHtml(array $request, string $signedUrl, string $notSignedUrl): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;background:#f5f7f4;font-family:Arial,Helvetica,sans-serif;color:#1f2428;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7f4;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dfe4dc;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="padding:22px 24px;background:#1f2428;color:#ffffff;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">MyTakii Intranet</div>'
            . '<h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;">Yillik izin defteri imza kontrolu</h1>'
            . '</td></tr>'
            . '<tr><td style="padding:22px 24px;">'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Bu calisan yillik izin defterini imzaladi mi? Lutfen asagidaki butonlardan biriyle durumu isaretleyin.</p>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 18px;">'
            . $this->approvalMailRow('Talep', $request['id'] ?? '')
            . $this->approvalMailRow('Talep sahibi', $request['requester'] ?? '')
            . $this->approvalMailRow('Tarih', $this->requestDateRange($request))
            . $this->approvalMailRow('Gun bolumu', $this->dayPartMailLabel($request))
            . $this->approvalMailRow('Gun', $this->requestTotalDaysLabel($request))
            . $this->approvalMailRow('Durum', 'Defter imzasi kontrol bekliyor')
            . '</table>'
            . '<table role="presentation" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="padding:0 10px 10px 0;"><a href="' . $escape($signedUrl) . '" style="display:inline-block;background:#24613b;color:#ffffff;text-decoration:none;font-weight:700;border-radius:7px;padding:12px 18px;">Imzalandi</a></td>'
            . '<td style="padding:0 0 10px 0;"><a href="' . $escape($notSignedUrl) . '" style="display:inline-block;background:#8a341f;color:#ffffff;text-decoration:none;font-weight:700;border-radius:7px;padding:12px 18px;">Imzalanmadi</a></td>'
            . '</tr></table>'
            . '<p style="margin:12px 0 0;font-size:12px;line-height:1.5;color:#66716a;">Bu takip maili, calisana gonderilen imza bildiriminden 2 gun sonra otomatik olusur.</p>'
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    private function approvalMailRow(string $label, mixed $value): string
    {
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        return '<tr>'
            . '<td style="width:150px;padding:8px 10px 8px 0;border-bottom:1px solid #edf0ea;color:#66716a;font-size:13px;">' . $label . '</td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #edf0ea;color:#1f2428;font-size:13px;font-weight:700;">' . $value . '</td>'
            . '</tr>';
    }

    private function notificationsForCurrentStage(array $request): array
    {
        $stage = $this->currentStage($request);

        if ($stage === null) {
            return [];
        }

        $recipient = $this->assigneeEmailForStage($request, $stage);

        if ($recipient === '' || !$this->assigneeCanViewRequest($request, $stage)) {
            return [];
        }

        return [[
            'request_id' => (string) ($request['id'] ?? ''),
            'stage' => $stage,
            'type' => $stage === self::CANCELLATION_STAGE ? 'leave_cancellation_approval' : 'leave_approval',
            'to_email' => $recipient,
            'requester' => (string) ($request['requester'] ?? ''),
            'starts_on' => (string) ($request['starts_on'] ?? ''),
            'ends_on' => (string) ($request['ends_on'] ?? ''),
            'date_range' => $this->requestDateRange($request),
            'day_part' => $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL),
            'day_part_key' => $this->dayPartKey($request),
            'total_days' => $this->requestTotalDaysLabel($request),
        ]];
    }

    private function assigneeEmailForStage(array $request, string $stage): string
    {
        $policy = $request['approval_policy'] ?? [];

        if ($stage === self::CANCELLATION_STAGE) {
            return (string) ($policy['manager_1_email'] ?? '');
        }

        return (string) ($policy[$stage . '_email'] ?? ($stage === 'hr' ? ($policy['hr_email'] ?? '') : ''));
    }

    private function assigneeCanViewRequest(array $request, string $stage): bool
    {
        $recipient = $this->assigneeEmailForStage($request, $stage);

        if ($recipient === '') {
            return false;
        }

        $profile = $this->userProfiles->find($recipient);

        if ($profile === null) {
            return false;
        }

        if ($this->accessControl !== null) {
            $profile['permissions'] = $this->accessControl->permissionsFor($recipient);
        }

        return LocationScope::canView($profile, $this->requesterProfileForRequest($request));
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = rtrim((string) (getenv('APP_URL') ?: 'http://127.0.0.1:8080'), '/');

        return $baseUrl . $path;
    }

    private function initialApprovals(int $managerCount, array $policy = []): array
    {
        return [
            'manager_1' => [
                'label_key' => 'leave.stage.manager_1',
                'assignee' => $policy['manager_1_email'] ?? '',
                'status' => 'pending',
                'actor' => null,
                'source' => null,
                'acted_at' => null,
            ],
            'manager_2' => [
                'label_key' => 'leave.stage.manager_2',
                'assignee' => $managerCount === 2 ? ($policy['manager_2_email'] ?? '') : '',
                'status' => $managerCount === 2 ? 'pending' : 'skipped',
                'actor' => null,
                'source' => null,
                'acted_at' => null,
            ],
            'hr' => [
                'label_key' => 'leave.stage.hr',
                'assignee' => $policy['hr_email'] ?? '',
                'status' => 'pending',
                'actor' => null,
                'source' => null,
                'acted_at' => null,
            ],
            'calendar' => [
                'label_key' => 'leave.stage.calendar',
                'assignee' => 'system',
                'status' => 'pending',
                'actor' => null,
                'source' => null,
                'acted_at' => null,
            ],
        ];
    }

    private function seed(): array
    {
        return [];
    }

    private function nextId(array $requests): string
    {
        $max = 1000;

        foreach ($requests as $request) {
            if (preg_match('/^LV-2026-(\d+)$/', (string) ($request['id'] ?? ''), $matches) !== 1) {
                continue;
            }

            $max = max($max, (int) $matches[1]);
        }

        return 'LV-2026-' . ($max + 1);
    }

    private function normalizeRequests(array $requests): array
    {
        return array_map(function (array $request): array {
            $request['requester_id'] = $this->requesterPersonnelId($request);
            $request['requester_location'] = LocationScope::locationForProfile($this->requesterProfileForRequest($request));
            $request['approval_tokens'] = array_merge([
                'manager_1' => null,
                'manager_2' => null,
                'hr' => null,
                self::CANCELLATION_STAGE => null,
            ], is_array($request['approval_tokens'] ?? null) ? $request['approval_tokens'] : []);

            foreach ($request['approval_tokens'] as $tokenStage => $storedToken) {
                $storedToken = trim((string) $storedToken);

                if ($storedToken !== '' && !str_starts_with($storedToken, 'sha256:')) {
                    $request['approval_tokens'][$tokenStage] = $this->hashOpaqueToken($storedToken);
                }
            }
            $request['approval_token_expires_at'] = array_merge([
                'manager_1' => null,
                'manager_2' => null,
                'hr' => null,
                self::CANCELLATION_STAGE => null,
            ], is_array($request['approval_token_expires_at'] ?? null) ? $request['approval_token_expires_at'] : []);
            $request['mail_notifications'] = is_array($request['mail_notifications'] ?? null) ? $request['mail_notifications'] : [];
            $request['cancellation_request'] = $this->normalizeCancellationRequest($request);
            $request['day_part'] = $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL);
            $request['total_days'] = (float) ($request['total_days'] ?? $this->leaveDurationDays(
                (string) ($request['starts_on'] ?? date('Y-m-d')),
                (string) ($request['ends_on'] ?? (string) ($request['starts_on'] ?? date('Y-m-d'))),
                $request['day_part']
            ));

            $stage = $this->currentStage($request);

            if ($stage !== null && empty($request['approval_token_expires_at'][$stage])) {
                $request['approval_token_expires_at'][$stage] = $this->tokenExpiresAt();
            }

            if ($this->shouldTrackLeaveBookSignature($request)) {
                $request['leave_book_signature'] = $this->normalizeLeaveBookSignatureState($request);
            }

            return $request;
        }, $requests);
    }

    private function shouldTrackLeaveBookSignature(array $request): bool
    {
        return ($request['type_key'] ?? '') === 'leave.type.annual'
            && ($request['status'] ?? '') === 'approved'
            && (
                is_array($request['leave_book_signature'] ?? null)
                || is_array($request['mail_notifications']['leave_book_signature'] ?? null)
            );
    }

    private function normalizeLeaveBookSignatureState(array $request): array
    {
        $signature = is_array($request['leave_book_signature'] ?? null) ? $request['leave_book_signature'] : [];
        $notification = is_array($request['mail_notifications']['leave_book_signature'] ?? null)
            ? $request['mail_notifications']['leave_book_signature']
            : [];
        $notificationDueAt = (string) ($signature['notification_due_at'] ?? '');

        if ($this->dateTimeOrNull($notificationDueAt) === null) {
            $notificationDueAt = $this->leaveBookSignatureNotificationDueAt($request);
        }

        $baseAt = (string) (
            $signature['required_at']
            ?? $notification['sent_at']
            ?? $notification['queued_at']
            ?? ''
        );
        $requiredAt = $this->dateTimeOrNull($baseAt)?->format('Y-m-d H:i');
        $dueAt = (string) ($signature['due_at'] ?? '');

        if ($this->dateTimeOrNull($dueAt) === null && $requiredAt !== null) {
            $dueAt = $this->addDays($requiredAt, $this->signatureFollowupDays());
        }

        $followupDueAt = (string) ($signature['followup_due_at'] ?? '');

        if ($this->dateTimeOrNull($followupDueAt) === null && $dueAt !== '') {
            $followupDueAt = $dueAt;
        }

        $status = (string) ($signature['status'] ?? 'waiting');
        $status = in_array($status, ['waiting', 'signed', 'not_signed'], true) ? $status : 'waiting';

        $followupToken = trim((string) ($signature['followup_token'] ?? ''));

        if ($followupToken !== '' && !str_starts_with($followupToken, 'sha256:')) {
            $followupToken = $this->hashOpaqueToken($followupToken);
        }

        return array_merge([
            'status' => $status,
            'required_at' => $requiredAt,
            'notification_due_at' => $notificationDueAt,
            'due_at' => $dueAt,
            'followup_due_at' => $followupDueAt,
            'followup_queued_at' => null,
            'followup_sent_at' => null,
            'followup_recipients' => [],
            'followup_outbox_ids' => [],
            'followup_token' => null,
            'followup_token_expires_at' => null,
            'acted_at' => null,
            'acted_by' => null,
            'source' => null,
        ], $signature, [
            'status' => $status,
            'required_at' => $requiredAt,
            'notification_due_at' => $notificationDueAt,
            'due_at' => $dueAt,
            'followup_due_at' => $followupDueAt,
            'followup_token' => $followupToken !== '' ? $followupToken : null,
        ]);
    }

    private function signatureNotificationQueued(array $request): bool
    {
        $notification = $request['mail_notifications']['leave_book_signature'] ?? null;

        if (!is_array($notification)) {
            return false;
        }

        return (string) ($notification['queued_at'] ?? '') !== ''
            || (string) ($notification['sent_at'] ?? '') !== ''
            || (string) ($notification['outbox_id'] ?? '') !== '';
    }

    private function leaveBookSignatureNotificationDueAt(array $request): string
    {
        $endsOn = $this->cleanDate((string) ($request['ends_on'] ?? ''));

        if ($endsOn === null) {
            return date('Y-m-d 09:00');
        }

        if ($this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL) === self::DAY_PART_MORNING) {
            return $endsOn . ' 13:00';
        }

        return $this->firstBusinessDayAfter($endsOn) . ' 09:00';
    }

    private function firstBusinessDayAfter(string $date): string
    {
        $day = (new DateTimeImmutable($date))->modify('+1 day');

        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('+1 day');
        }

        return $day->format('Y-m-d');
    }

    private function signatureFollowupRecipients(array $request): array
    {
        $configured = trim((string) getenv('LEAVE_BOOK_SIGNATURE_FOLLOWUP_EMAILS'));
        $candidates = $configured !== ''
            ? preg_split('/[\s,;]+/', $configured, -1, PREG_SPLIT_NO_EMPTY)
            : [];

        if ($configured === '') {
            $candidates[] = (string) ($request['approval_policy']['hr_email'] ?? '');
        }

        $requestLocation = LocationScope::locationForProfile($this->requesterProfileForRequest($request));
        $assistantRoles = ['hr_assistant'];

        if ($requestLocation === LocationScope::ANTALYA) {
            $assistantRoles[] = 'hr_assistant_antalya';
        } elseif ($requestLocation === LocationScope::BURSA) {
            $assistantRoles[] = 'hr_assistant_bursa';
        } else {
            $assistantRoles[] = 'hr_assistant_antalya';
            $assistantRoles[] = 'hr_assistant_bursa';
        }

        foreach ($assistantRoles as $assistantRole) {
            foreach ($this->accessControl?->usersWithWorkforceRole($assistantRole) ?? [] as $user) {
                $candidates[] = (string) ($user['email'] ?? '');
            }
        }

        $recipients = [];

        foreach ($candidates ?: [] as $candidate) {
            $email = strtolower(trim((string) $candidate));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipients[$email] = $email;
        }

        return array_values($recipients);
    }

    private function signatureFollowupDays(): int
    {
        $days = (int) (getenv('LEAVE_BOOK_SIGNATURE_FOLLOWUP_DAYS') ?: self::LEAVE_BOOK_SIGNATURE_FOLLOWUP_DAYS);

        return $days > 0 ? $days : self::LEAVE_BOOK_SIGNATURE_FOLLOWUP_DAYS;
    }

    private function signatureTokenTtlDays(): int
    {
        $days = (int) (getenv('LEAVE_BOOK_SIGNATURE_TOKEN_TTL_DAYS') ?: self::LEAVE_BOOK_SIGNATURE_TOKEN_TTL_DAYS);

        return $days > 0 ? $days : self::LEAVE_BOOK_SIGNATURE_TOKEN_TTL_DAYS;
    }

    private function addDays(string $dateTime, int $days): string
    {
        $date = $this->dateTimeOrNull($dateTime) ?? new DateTimeImmutable('now');

        return $date->modify('+' . $days . ' days')->format('Y-m-d H:i');
    }

    private function dateTimeOrNull(string $dateTime): ?DateTimeImmutable
    {
        $dateTime = trim($dateTime);

        if ($dateTime === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($dateTime);
        } catch (\Exception) {
            return null;
        }
    }

    private function mailBatchStatus(array $entries): string
    {
        if ($entries === []) {
            return 'queued';
        }

        $statuses = array_map(static fn (array $entry): string => (string) ($entry['status'] ?? 'queued'), $entries);

        if (count(array_unique($statuses)) === 1) {
            return $statuses[0];
        }

        return in_array('sent', $statuses, true) || in_array('queued', $statuses, true) ? 'partial' : $statuses[0];
    }

    private function mailBatchTransports(array $entries): string
    {
        $transports = array_filter(array_unique(array_map(static fn (array $entry): string => (string) ($entry['transport'] ?? ''), $entries)));

        return implode(', ', $transports);
    }

    private function mailBatchErrors(array $entries): string
    {
        $errors = array_filter(array_map(static fn (array $entry): string => (string) ($entry['error'] ?? ''), $entries));

        return implode('; ', $errors);
    }

    private function allMailEntriesSent(array $entries): bool
    {
        return $entries !== [] && array_reduce(
            $entries,
            static fn (bool $carry, array $entry): bool => $carry && (string) ($entry['status'] ?? '') === 'sent',
            true
        );
    }

    private function loadRequests(): ?array
    {
        $decoded = $this->stateStore->read(self::REQUESTS_STATE_KEY, $this->requestsPath());

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::STORAGE_VERSION || !is_array($decoded['requests'] ?? null)) {
            return null;
        }

        return $decoded['requests'];
    }

    private function cleanDate(string $date): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);

        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function cleanDayPart(mixed $value): string
    {
        $dayPart = is_string($value) ? trim($value) : '';

        return in_array($dayPart, self::DAY_PARTS, true) ? $dayPart : self::DAY_PART_FULL;
    }

    private function cleanDecisionNote(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return substr($value, 0, 500);
    }

    private function normalizeSearchText(string $value): string
    {
        $value = strtr($value, [
            'İ' => 'i',
            'I' => 'i',
            'ı' => 'i',
            'Ş' => 's',
            'ş' => 's',
            'Ğ' => 'g',
            'ğ' => 'g',
            'Ü' => 'u',
            'ü' => 'u',
            'Ö' => 'o',
            'ö' => 'o',
            'Ç' => 'c',
            'ç' => 'c',
        ]);

        return strtolower(trim($value));
    }

    private function isHalfDayPart(string $dayPart): bool
    {
        return in_array($dayPart, [self::DAY_PART_MORNING, self::DAY_PART_AFTERNOON], true);
    }

    private function leaveDurationDays(string $startsOn, string $endsOn, string $dayPart, ?array $user = null): float
    {
        $start = new DateTimeImmutable($startsOn);
        $end = (new DateTimeImmutable($endsOn))->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        $days = 0.0;

        foreach ($period as $day) {
            $days += $this->leaveChargeForDate($day, $dayPart, $user);
        }

        return round($days, 2);
    }

    private function formatDays(mixed $value): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        if (abs($number - round($number)) < 0.001) {
            return (string) (int) round($number);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function dayPartKey(array $request): string
    {
        return 'leave.day_part.' . $this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL);
    }

    private function dayPartMailLabel(array $request): string
    {
        return match ($this->cleanDayPart($request['day_part'] ?? self::DAY_PART_FULL)) {
            self::DAY_PART_MORNING => 'Ogleden once',
            self::DAY_PART_AFTERNOON => 'Ogleden sonra',
            default => 'Tam gun',
        };
    }

    private function requestDateRange(array $request): string
    {
        $startsOn = (string) ($request['starts_on'] ?? '');
        $endsOn = (string) ($request['ends_on'] ?? '');

        if ($endsOn === '' || $startsOn === $endsOn) {
            return $startsOn;
        }

        return $startsOn . ' - ' . $endsOn;
    }

    private function requestTotalDaysLabel(array $request): string
    {
        return $this->formatDays($request['total_days'] ?? 0);
    }

    private function leaveChargeForDate(DateTimeImmutable $day, string $dayPart, ?array $user): float
    {
        if (!$this->isWorkingDayForUser($day, $user)) {
            return 0.0;
        }

        $holiday = $this->shiftStore->holidayForDate($day->format('Y-m-d'));
        $holidayPart = (string) ($holiday['day_part'] ?? '');

        if ($holidayPart === self::DAY_PART_FULL) {
            return 0.0;
        }

        if ($dayPart === self::DAY_PART_MORNING) {
            return 0.5;
        }

        if ($dayPart === self::DAY_PART_AFTERNOON) {
            return $holidayPart === self::DAY_PART_AFTERNOON ? 0.0 : 0.5;
        }

        return $holidayPart === self::DAY_PART_AFTERNOON ? 0.5 : 1.0;
    }

    private function isWorkingDayForUser(DateTimeImmutable $day, ?array $user): bool
    {
        if ($user === null) {
            return (int) $day->format('N') < 6;
        }

        return $this->shiftStore->isWorkingDateForUser($user, $day);
    }

    private function save(array $requests): void
    {
        Session::put(self::SESSION_KEY, array_values($requests));
        $this->stateStore->write(self::REQUESTS_STATE_KEY, $this->requestsPath(), [
            'version' => self::STORAGE_VERSION,
            'requests' => array_values($requests),
        ]);
    }

    private function appendMailOutbox(array $entry): void
    {
        $writeGuard = $this->stateStore->beginWrite(self::MAIL_OUTBOX_STATE_KEY, $this->mailOutboxPath());
        $decoded = $this->stateStore->read(self::MAIL_OUTBOX_STATE_KEY, $this->mailOutboxPath());
        $outbox = is_array($decoded) && is_array($decoded['messages'] ?? null)
            ? $decoded
            : ['version' => self::STORAGE_VERSION, 'messages' => []];
        $outbox['messages'][] = $entry;

        $this->stateStore->write(self::MAIL_OUTBOX_STATE_KEY, $this->mailOutboxPath(), $outbox);
    }

    private function redactedMailEntry(array $entry): array
    {
        $sensitiveKeys = [
            'body_html',
            'approve_url',
            'reject_url',
            'signed_url',
            'not_signed_url',
        ];
        $redacted = false;

        foreach ($sensitiveKeys as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }

            unset($entry[$key]);
            $redacted = true;
        }

        if ($redacted) {
            $entry['sensitive_content_redacted'] = true;
        }

        return $entry;
    }

    private function redactStoredMailOutbox(): void
    {
        $writeGuard = $this->stateStore->beginWrite(self::MAIL_OUTBOX_STATE_KEY, $this->mailOutboxPath());
        $outbox = $this->stateStore->read(
            self::MAIL_OUTBOX_STATE_KEY,
            $this->mailOutboxPath(),
            ['version' => self::STORAGE_VERSION, 'messages' => []]
        );

        if (!is_array($outbox) || !is_array($outbox['messages'] ?? null)) {
            return;
        }

        $messages = array_map(fn (array $entry): array => $this->redactedMailEntry($entry), $outbox['messages']);

        if ($messages === $outbox['messages']) {
            return;
        }

        $outbox['messages'] = $messages;
        $this->stateStore->write(self::MAIL_OUTBOX_STATE_KEY, $this->mailOutboxPath(), $outbox);
    }

    private function writeGuard(): StateWriteGuard
    {
        return $this->stateStore->beginWrite(self::REQUESTS_STATE_KEY, $this->requestsPath());
    }

    private function requestsPath(): string
    {
        return $this->storageRoot() . '/leave-requests.json';
    }

    private function mailOutboxPath(): string
    {
        return $this->storageRoot() . '/leave-mail-outbox.json';
    }

    private function storageRoot(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage';
    }
}
