<?php

namespace App\Core;

class Auth
{
    public function __construct(
        private readonly UserProfileStore $userProfiles,
        private readonly AccessControl $accessControl,
    ) {
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->userProfiles->verifyCredentials($email, $password);

        if ($user === null) {
            return false;
        }

        $profileKey = (string) ($user['profile_key'] ?? $email);

        Session::put('user', [
            'email' => $profileKey,
            'personnel_id' => $user['personnel_id'] ?? '',
            'username' => $user['username'] ?? '',
            'name' => $user['name'],
            'role' => $user['role'],
            'department' => $user['department'],
            'location' => LocationScope::locationForProfile($user),
            'workforce_roles' => is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : [],
            'started_on' => $user['started_on'] ?? null,
            'birth_date' => $user['birth_date'] ?? null,
            'leave_opening_total_days' => $user['leave_opening_total_days'] ?? 0,
            'leave_opening_used_days' => $user['leave_opening_used_days'] ?? 0,
            'leave_opening_remaining_days' => $user['leave_opening_remaining_days'] ?? 0,
            'leave_opening_snapshot_date' => $user['leave_opening_snapshot_date'] ?? '',
            'leave_opening_source' => $user['leave_opening_source'] ?? '',
            'shift_key' => $user['shift_key'] ?? '',
            'permissions' => $this->accessControl->permissionsFor($profileKey),
        ]);

        return true;
    }

    public function logout(): void
    {
        Session::forget('user');
    }

    public function user(): ?array
    {
        $user = Session::get('user');

        if (!is_array($user)) {
            return null;
        }

        if (isset($user['email'])) {
            $email = (string) $user['email'];
            $sourceUser = $this->userProfiles->find($email) ?? [];
            $user['personnel_id'] = $sourceUser['personnel_id'] ?? $user['personnel_id'] ?? '';
            $user['username'] = $sourceUser['username'] ?? $user['username'] ?? '';
            $user['name'] = $sourceUser['name'] ?? $user['name'] ?? '';
            $user['role'] = $sourceUser['role'] ?? $user['role'] ?? '';
            $user['department'] = $sourceUser['department'] ?? $user['department'] ?? '';
            $user['location'] = LocationScope::locationForProfile($sourceUser !== [] ? $sourceUser : $user);
            $user['workforce_roles'] = is_array($sourceUser['workforce_roles'] ?? null)
                ? $sourceUser['workforce_roles']
                : (is_array($user['workforce_roles'] ?? null) ? $user['workforce_roles'] : []);
            $user['started_on'] = $sourceUser['started_on'] ?? $user['started_on'] ?? null;
            $user['birth_date'] = $sourceUser['birth_date'] ?? $user['birth_date'] ?? null;
            $user['leave_opening_total_days'] = $sourceUser['leave_opening_total_days'] ?? $user['leave_opening_total_days'] ?? 0;
            $user['leave_opening_used_days'] = $sourceUser['leave_opening_used_days'] ?? $user['leave_opening_used_days'] ?? 0;
            $user['leave_opening_remaining_days'] = $sourceUser['leave_opening_remaining_days'] ?? $user['leave_opening_remaining_days'] ?? 0;
            $user['leave_opening_snapshot_date'] = $sourceUser['leave_opening_snapshot_date'] ?? $user['leave_opening_snapshot_date'] ?? '';
            $user['leave_opening_source'] = $sourceUser['leave_opening_source'] ?? $user['leave_opening_source'] ?? '';
            $user['shift_key'] = $sourceUser['shift_key'] ?? $user['shift_key'] ?? '';
            $user['permissions'] = $this->accessControl->permissionsFor($email);
            Session::put('user', $user);
        }

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function can(string $permission): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $permissions = $user['permissions'] ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
