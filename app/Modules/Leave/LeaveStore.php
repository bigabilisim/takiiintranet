<?php

namespace App\Modules\Leave;

use App\Core\AccessControl;
use App\Core\Auth;
use App\Core\Session;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

class LeaveStore
{
    private const SESSION_KEY = 'leave_requests';
    private const STORAGE_VERSION = 1;
    private const TOKEN_TTL_HOURS = 96;

    public function __construct(private readonly ?AccessControl $accessControl = null)
    {
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
        $this->save($requests);

        return $requests;
    }

    public function create(array $user, array $input): array
    {
        $requests = $this->all();
        $startsOn = $this->cleanDate((string) ($input['starts_on'] ?? ''));
        $endsOn = $this->cleanDate((string) ($input['ends_on'] ?? ''));

        if ($startsOn === null || $endsOn === null || $startsOn > $endsOn) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_dates'];
        }

        $typeKey = (string) ($input['type_key'] ?? 'leave.type.annual');
        $totalDays = $this->businessDays($startsOn, $endsOn);

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
            'requester' => $user['name'] ?? 'Unknown',
            'department' => $user['department'] ?? 'General',
            'type_key' => $typeKey,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
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

        $requests[] = $request;
        $this->save($requests);

        return [
            'ok' => true,
            'message' => 'leave.flash.created',
            'notifications' => $this->notificationsForCurrentStage($request),
        ];
    }

    public function advanceByPlatform(string $id, array $actor, Auth $auth, string $decision): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_decision'];
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

            $requests[$index] = $this->applyDecision($request, $stage, $decision, $actor['name'] ?? 'Platform', 'platform');
            $this->save($requests);

            return [
                'ok' => true,
                'message' => $decision === 'approve' ? 'leave.flash.approved' : 'leave.flash.rejected',
                'notifications' => $this->notificationsForCurrentStage($requests[$index]),
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.not_found'];
    }

    public function advanceByToken(string $token, string $decision): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'leave.flash.invalid_decision', 'request' => null];
        }

        $requests = $this->all();

        foreach ($requests as $index => $request) {
            $stage = $this->currentStage($request);

            if ($stage === null || ($request['approval_tokens'][$stage] ?? '') !== $token) {
                continue;
            }

            if ($this->tokenExpired($request, $stage)) {
                return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => $request];
            }

            $requests[$index] = $this->applyDecision($request, $stage, $decision, 'Mail approval', 'email');
            $this->save($requests);

            return [
                'ok' => true,
                'message' => $decision === 'approve' ? 'leave.flash.mail_approved' : 'leave.flash.mail_rejected',
                'request' => $requests[$index],
                'notifications' => $this->notificationsForCurrentStage($requests[$index]),
            ];
        }

        return ['ok' => false, 'message' => 'leave.flash.token_expired', 'request' => null];
    }

    public function decorateForUser(array $request, Auth $auth): array
    {
        $stage = $this->currentStage($request);
        $request['current_stage'] = $stage;
        $request['current_stage_key'] = $stage ? 'leave.stage.' . $stage : 'leave.stage.done';
        $request['status_key'] = 'leave.status.' . $request['status'];
        $request['can_act'] = $stage !== null && $this->canActOnStage($stage, $auth, $request);
        $request['mail_approve_url'] = $stage ? '/leave/mail-approval/' . $request['approval_tokens'][$stage] . '/approve' : null;
        $request['mail_reject_url'] = $stage ? '/leave/mail-approval/' . $request['approval_tokens'][$stage] . '/reject' : null;
        $request['mail_token_expires_at'] = $stage ? ($request['approval_token_expires_at'][$stage] ?? null) : null;

        return $request;
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
                $syncedRequest['approval_tokens'][$newStage] = bin2hex(random_bytes(16));
                $syncedRequest['approval_token_expires_at'][$newStage] = $this->tokenExpiresAt();
                $syncedRequest = $this->queueApprovalMail($syncedRequest, $newStage);
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

    public function balanceForUser(array $user): array
    {
        $name = (string) ($user['name'] ?? '');
        $ledger = $this->entitlementLedgerForUser($user);
        $earnedDays = array_sum(array_map(fn (array $entry): int => (int) ($entry['days'] ?? 0), $ledger));
        $openingTotalDays = (float) ($user['leave_opening_total_days'] ?? 0);
        $openingUsedDays = (float) ($user['leave_opening_used_days'] ?? 0);
        $openingRemainingDays = (float) ($user['leave_opening_remaining_days'] ?? 0);
        $earnedDays = max($earnedDays, $openingTotalDays);
        $currentEntitlement = $this->currentEntitlementDaysForUser($user);
        $usedDays = $openingUsedDays;
        $pendingDays = 0.0;

        foreach ($this->all() as $request) {
            if (($request['requester'] ?? '') !== $name || ($request['type_key'] ?? '') !== 'leave.type.annual') {
                continue;
            }

            if (($request['status'] ?? '') === 'approved') {
                $usedDays += (int) ($request['total_days'] ?? 0);
                continue;
            }

            if (in_array($request['status'] ?? '', ['waiting_manager_1', 'waiting_manager_2', 'waiting_hr'], true)) {
                $pendingDays += (int) ($request['total_days'] ?? 0);
            }
        }

        return [
            'allowance_days' => $earnedDays,
            'current_entitlement_days' => $currentEntitlement,
            'service_years' => $this->completedServiceYears($user),
            'used_days' => $usedDays,
            'pending_days' => $pendingDays,
            'remaining_days' => max(0, $earnedDays - $usedDays - $pendingDays),
            'ledger' => $ledger,
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

    public function calendar(string $view, ?string $focusDate = null, ?array $user = null): array
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
                'events' => $this->eventsForDate($date, $user, $upcomingEntitlement),
            ];
        }

        return [
            'view' => $view,
            'focus' => $focus,
            'title' => $this->titleFor($view, $focusDateTime, $start, $end),
            'days' => $days,
        ];
    }

    private function applyDecision(array $request, string $stage, string $decision, string $actor, string $source): array
    {
        $request['approvals'][$stage]['status'] = $decision === 'approve' ? 'approved' : 'rejected';
        $request['approvals'][$stage]['actor'] = $actor;
        $request['approvals'][$stage]['source'] = $source;
        $request['approvals'][$stage]['acted_at'] = date('Y-m-d H:i');

        if ($decision === 'reject') {
            $request['status'] = 'rejected';
            $request['calendar_state'] = 'blocked';
            $request['approvals']['calendar']['status'] = 'rejected';

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

    private function currentStage(array $request): ?string
    {
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
        $userEmail = $user['email'] ?? '';
        $policy = $request['approval_policy'] ?? $this->accessControl?->departmentPolicy($request['department'] ?? '') ?? [];

        if ($stage === 'hr') {
            $hrEmail = $policy['hr_email'] ?? '';

            return $auth->can('leave.request.manage.hr') && ($hrEmail === '' || $userEmail === $hrEmail);
        }

        $managerEmail = $policy[$stage . '_email'] ?? '';

        return $auth->can('leave.request.approve.department') && ($managerEmail === '' || $userEmail === $managerEmail);
    }

    private function eventsForDate(string $date, ?array $user = null, ?array $upcomingEntitlement = null): array
    {
        $events = [];
        $currentUserName = is_array($user) ? (string) ($user['name'] ?? '') : '';

        foreach ($this->all() as $request) {
            if ($date < $request['starts_on'] || $date > $request['ends_on']) {
                continue;
            }

            $events[] = [
                'id' => $request['id'],
                'requester' => $request['requester'],
                'department' => $request['department'] ?? '',
                'type_key' => $request['type_key'] ?? 'leave.type.annual',
                'starts_on' => $request['starts_on'],
                'ends_on' => $request['ends_on'],
                'total_days' => $request['total_days'] ?? 0,
                'status' => $request['status'],
                'status_key' => 'leave.status.' . $request['status'],
                'calendar_state' => $request['calendar_state'],
                'note' => $request['note'] ?? '',
                'approvals' => is_array($request['approvals'] ?? null) ? $request['approvals'] : [],
                'entitlement_hint' => $currentUserName !== '' && ($request['requester'] ?? '') === $currentUserName ? $upcomingEntitlement : null,
            ];
        }

        return $events;
    }

    private function titleFor(string $view, DateTimeImmutable $focus, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return match ($view) {
            'week' => $start->format('d M') . ' - ' . $end->format('d M Y'),
            'day' => $focus->format('d M Y'),
            default => $focus->format('F Y'),
        };
    }

    private function currentEntitlementDaysForUser(array $user): int
    {
        $serviceYears = max(1, $this->completedServiceYears($user));
        $asOf = new DateTimeImmutable(date('Y-m-d'));

        return $this->entitlementDaysForServiceYear($user, $serviceYears, $asOf);
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

        if ($serviceYear <= 5) {
            $days = 14;
        } elseif ($serviceYear < 15) {
            $days = 20;
        } else {
            $days = 26;
        }

        $age = $this->ageAt($user, $entitlementDate);

        if ($age !== null && ($age <= 18 || $age >= 50)) {
            $days = max($days, 20);
        }

        return $days;
    }

    private function entitlementRuleKey(array $user, int $serviceYear, DateTimeImmutable $entitlementDate): string
    {
        $age = $this->ageAt($user, $entitlementDate);

        if ($age !== null && ($age <= 18 || $age >= 50)) {
            return 'leave.entitlement.rule.age';
        }

        if ($serviceYear <= 5) {
            return 'leave.entitlement.rule.one_to_five';
        }

        if ($serviceYear < 15) {
            return 'leave.entitlement.rule.more_than_five';
        }

        return 'leave.entitlement.rule.fifteen_plus';
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

        $request['approval_tokens'][$stage] = bin2hex(random_bytes(16));
        $request['approval_token_expires_at'][$stage] = $this->tokenExpiresAt();

        return $queueMail ? $this->queueApprovalMail($request, $stage) : $request;
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

    private function queueApprovalMail(array $request, string $stage): array
    {
        $recipient = $this->assigneeEmailForStage($request, $stage);

        if ($recipient === '') {
            return $request;
        }

        $token = (string) ($request['approval_tokens'][$stage] ?? '');
        $approvePath = '/leave/mail-approval/' . $token . '/approve';
        $rejectPath = '/leave/mail-approval/' . $token . '/reject';
        $entry = [
            'id' => 'MAIL-' . bin2hex(random_bytes(6)),
            'type' => 'leave_approval',
            'request_id' => $request['id'],
            'stage' => $stage,
            'to_email' => $recipient,
            'subject' => 'Izin onayi: ' . $request['requester'] . ' / ' . $request['starts_on'] . ' - ' . $request['ends_on'],
            'body' => implode("\n", [
                'Izin talebi onayinizi bekliyor.',
                'Talep: ' . $request['id'],
                'Talep sahibi: ' . $request['requester'],
                'Tarih: ' . $request['starts_on'] . ' - ' . $request['ends_on'],
                'Gun: ' . $request['total_days'],
                'Onay linki: ' . $this->absoluteUrl($approvePath),
                'Red linki: ' . $this->absoluteUrl($rejectPath),
                'Token gecerlilik: ' . self::TOKEN_TTL_HOURS . ' saat, son tarih ' . ($request['approval_token_expires_at'][$stage] ?? ''),
            ]),
            'approve_url' => $this->absoluteUrl($approvePath),
            'reject_url' => $this->absoluteUrl($rejectPath),
            'token_expires_at' => $request['approval_token_expires_at'][$stage] ?? null,
            'created_at' => date('Y-m-d H:i'),
        ];

        $this->appendMailOutbox($entry);
        $request['mail_notifications'][$stage] = [
            'to_email' => $recipient,
            'sent_at' => $entry['created_at'],
            'token_expires_at' => $entry['token_expires_at'],
            'outbox_id' => $entry['id'],
        ];

        return $request;
    }

    private function notificationsForCurrentStage(array $request): array
    {
        $stage = $this->currentStage($request);

        if ($stage === null) {
            return [];
        }

        $recipient = $this->assigneeEmailForStage($request, $stage);

        if ($recipient === '') {
            return [];
        }

        return [[
            'request_id' => (string) ($request['id'] ?? ''),
            'stage' => $stage,
            'to_email' => $recipient,
            'requester' => (string) ($request['requester'] ?? ''),
            'starts_on' => (string) ($request['starts_on'] ?? ''),
            'ends_on' => (string) ($request['ends_on'] ?? ''),
            'total_days' => (string) ($request['total_days'] ?? ''),
        ]];
    }

    private function assigneeEmailForStage(array $request, string $stage): string
    {
        $policy = $request['approval_policy'] ?? [];

        return (string) ($policy[$stage . '_email'] ?? ($stage === 'hr' ? ($policy['hr_email'] ?? '') : ''));
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
        return 'LV-2026-' . (1001 + count($requests));
    }

    private function normalizeRequests(array $requests): array
    {
        return array_map(function (array $request): array {
            $request['approval_tokens'] = array_merge([
                'manager_1' => null,
                'manager_2' => null,
                'hr' => null,
            ], is_array($request['approval_tokens'] ?? null) ? $request['approval_tokens'] : []);
            $request['approval_token_expires_at'] = array_merge([
                'manager_1' => null,
                'manager_2' => null,
                'hr' => null,
            ], is_array($request['approval_token_expires_at'] ?? null) ? $request['approval_token_expires_at'] : []);
            $request['mail_notifications'] = is_array($request['mail_notifications'] ?? null) ? $request['mail_notifications'] : [];

            $stage = $this->currentStage($request);

            if ($stage !== null && empty($request['approval_token_expires_at'][$stage])) {
                $request['approval_token_expires_at'][$stage] = $this->tokenExpiresAt();
            }

            if ($stage !== null && empty($request['approval_tokens'][$stage])) {
                $request['approval_tokens'][$stage] = bin2hex(random_bytes(16));
            }

            return $request;
        }, $requests);
    }

    private function loadRequests(): ?array
    {
        $path = $this->requestsPath();

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

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

    private function businessDays(string $startsOn, string $endsOn): int
    {
        $start = new DateTimeImmutable($startsOn);
        $end = (new DateTimeImmutable($endsOn))->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        $days = 0;

        foreach ($period as $day) {
            if ((int) $day->format('N') < 6) {
                $days++;
            }
        }

        return max(1, $days);
    }

    private function save(array $requests): void
    {
        Session::put(self::SESSION_KEY, array_values($requests));
        $path = $this->requestsPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode([
            'version' => self::STORAGE_VERSION,
            'requests' => array_values($requests),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function appendMailOutbox(array $entry): void
    {
        $path = $this->mailOutboxPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $outbox = is_array($decoded) && is_array($decoded['messages'] ?? null)
            ? $decoded
            : ['version' => self::STORAGE_VERSION, 'messages' => []];
        $outbox['messages'][] = $entry;

        file_put_contents($path, json_encode($outbox, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
