<?php

declare(strict_types=1);

namespace App\Core;

final class PersonnelDataPolicy
{
    private const SENSITIVE_FIELDS = [
        'personal_phone',
        'birth_date',
        'leave_opening_source',
        'national_id',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'hr_notes',
    ];

    public static function canViewSensitive(array $viewer): bool
    {
        $permissions = is_array($viewer['permissions'] ?? null) ? $viewer['permissions'] : [];

        if (in_array('*', $permissions, true) || in_array('admin.company.manage', $permissions, true)) {
            return true;
        }

        $roles = is_array($viewer['workforce_roles'] ?? null) ? $viewer['workforce_roles'] : [];

        return array_intersect($roles, [
            'hr',
            'hr_assistant',
            'hr_assistant_antalya',
            'hr_assistant_bursa',
        ]) !== [];
    }

    public static function project(array $profile, array $viewer): array
    {
        if (self::canViewSensitive($viewer)) {
            return $profile;
        }

        foreach (self::SENSITIVE_FIELDS as $field) {
            unset($profile[$field]);
        }

        return $profile;
    }
}
