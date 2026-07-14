<?php

declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\PasswordResetStore;
use App\Modules\Leave\LeaveStore;
use App\Modules\Messaging\MessageStore;
use App\Modules\Notifications\PushNotificationStore;
use App\Modules\Shift\ShiftStore;
use Throwable;

final class UserIdentityMigrationService
{
    public function __construct(
        private readonly StateStore $stateStore,
        private readonly UserProfileStore $userProfiles,
        private readonly AccessControl $accessControl,
        private readonly LeaveStore $leaveStore,
        private readonly MessageStore $messageStore,
        private readonly PushNotificationStore $pushStore,
        private readonly ShiftStore $shiftStore,
        private readonly PasswordResetStore $passwordResetStore,
    ) {
    }

    public function updateProfile(string $profileKey, array $input): array
    {
        $before = $this->userProfiles->find($profileKey);

        if ($before === null) {
            return ['ok' => false, 'message' => 'admin.flash.user_not_found'];
        }

        $oldProfileKey = (string) ($before['profile_key'] ?? $profileKey);
        $oldEmail = strtolower(trim((string) ($before['email'] ?? '')));
        $newEmailInput = array_key_exists('new_email', $input)
            ? trim((string) $input['new_email'])
            : $oldEmail;

        if (strtolower($newEmailInput) === $oldEmail) {
            return $this->userProfiles->updateProfile($oldProfileKey, $input);
        }

        if ($newEmailInput === '' && $this->accessControl->isApprovalAssignee($oldProfileKey)) {
            return ['ok' => false, 'message' => 'personnel.flash.email_required_for_approver'];
        }

        $fallbackPermissions = $this->accessControl->permissionsFor($oldProfileKey);

        try {
            $result = $this->stateStore->transaction(
                $this->identityDocuments(),
                function () use ($oldProfileKey, $oldEmail, $input, $fallbackPermissions): array {
                    $result = $this->userProfiles->updateProfile($oldProfileKey, $input, false);

                    if (empty($result['ok'])) {
                        return $result;
                    }

                    $newProfileKey = (string) ($result['profile_key'] ?? '');

                    if ($newProfileKey === '' || $newProfileKey === $oldProfileKey) {
                        return $result;
                    }

                    $migration = [
                        'access' => $this->accessControl->migrateUserIdentity(
                            $oldProfileKey,
                            $newProfileKey,
                            $fallbackPermissions
                        ),
                        'leave' => $this->leaveStore->migrateUserIdentity($oldProfileKey, $newProfileKey),
                        'messages' => $this->messageStore->migrateUserIdentity($oldProfileKey, $newProfileKey),
                        'push_subscriptions' => $this->pushStore->migrateUserIdentity($oldProfileKey, $newProfileKey),
                        'weekend_plans' => $this->shiftStore->migrateUserIdentity($oldProfileKey, $newProfileKey),
                        'password_resets_revoked' => $this->passwordResetStore->revokeForIdentity(
                            $oldProfileKey,
                            $oldEmail
                        ),
                    ];

                    $result['identity_migrated'] = true;
                    $result['old_profile_key'] = $oldProfileKey;
                    $result['new_profile_key'] = $newProfileKey;
                    $result['migration'] = $migration;

                    return $result;
                }
            );
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'personnel.flash.identity_migration_failed'];
        }

        if (!empty($result['ok'])) {
            $newProfileKey = (string) ($result['new_profile_key'] ?? $result['profile_key'] ?? $oldProfileKey);
            $directoryUsers = $this->userProfiles->users();
            $this->accessControl->replaceDirectoryUsers($directoryUsers);
            $this->messageStore->replaceDirectoryUsers($this->accessControl->usersByIdentity());

            if (!empty($result['identity_migrated'])) {
                $this->userProfiles->syncSessionAfterProfileUpdate($oldProfileKey, $newProfileKey);
                $this->leaveStore->refreshScheduleCache();
            }

            $currentUser = Session::get('user');

            if (is_array($currentUser) && (string) ($currentUser['email'] ?? '') === $newProfileKey) {
                $currentUser['permissions'] = $this->accessControl->permissionsFor($newProfileKey);
                Session::put('user', $currentUser);
            }
        }

        return $result;
    }

    private function identityDocuments(): array
    {
        $storage = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage';

        return [
            [
                'key' => 'password_resets',
                'path' => $storage . '/password-resets.json',
                'default' => ['version' => 1, 'requests' => []],
            ],
            [
                'key' => 'user_profiles',
                'path' => $storage . '/user-profiles.json',
                'default' => ['version' => 1, 'profiles' => []],
            ],
            [
                'key' => 'access_control',
                'path' => $storage . '/access-control.json',
                'default' => ['version' => 15, 'departments' => [], 'user_permissions' => [], 'department_policies' => []],
            ],
            [
                'key' => 'leave_requests',
                'path' => $storage . '/leave-requests.json',
                'default' => ['version' => 1, 'requests' => []],
            ],
            [
                'key' => 'leave_mail_outbox',
                'path' => $storage . '/leave-mail-outbox.json',
                'default' => ['version' => 1, 'messages' => []],
            ],
            [
                'key' => 'messages',
                'path' => $storage . '/messages.json',
                'default' => ['version' => 2, 'messages' => [], 'pinned_conversations' => []],
            ],
            [
                'key' => 'push_subscriptions',
                'path' => $storage . '/push-subscriptions.json',
                'default' => ['version' => 1, 'subscriptions' => []],
            ],
            [
                'key' => 'shifts',
                'path' => $storage . '/shifts.json',
                'default' => [
                    'version' => 1,
                    'templates' => [],
                    'deleted_seed_templates' => [],
                    'weekend_plans' => [],
                ],
            ],
        ];
    }
}
