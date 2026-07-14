<?php

namespace App\Core;

final class LocationScope
{
    public const ANTALYA = 'antalya';
    public const BURSA = 'bursa';

    private const LOCATIONS = [
        self::ANTALYA,
        self::BURSA,
    ];

    public static function options(): array
    {
        return [
            self::ANTALYA => 'location.antalya',
            self::BURSA => 'location.bursa',
        ];
    }

    public static function normalize(string $location): string
    {
        $location = self::normalizeText($location);

        if (in_array($location, self::LOCATIONS, true)) {
            return $location;
        }

        $hasAntalya = str_contains($location, self::ANTALYA);
        $hasBursa = str_contains($location, self::BURSA);

        if ($hasAntalya !== $hasBursa) {
            return $hasAntalya ? self::ANTALYA : self::BURSA;
        }

        return '';
    }

    public static function locationForProfile(array $profile): string
    {
        $explicit = self::normalize((string) ($profile['location'] ?? ''));

        if ($explicit !== '') {
            return $explicit;
        }

        $shiftKey = self::normalizeText((string) ($profile['shift_key'] ?? ''));

        if (str_contains($shiftKey, self::BURSA)) {
            return self::BURSA;
        }

        if (str_contains($shiftKey, self::ANTALYA)) {
            return self::ANTALYA;
        }

        $department = self::normalizeText((string) ($profile['department'] ?? ''));

        if (str_contains($department, 'prod')) {
            return self::BURSA;
        }

        foreach (['rd', 'arastirma', 'research', 'test', 'operations', 'muhasebe', 'finance', 'finans', 'ik', 'hr'] as $antalyaDepartment) {
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($antalyaDepartment, '/') . '([^a-z0-9]|$)/', $department) === 1) {
                return self::ANTALYA;
            }
        }

        return '';
    }

    public static function hasGlobalVisibility(array $viewer): bool
    {
        $permissions = is_array($viewer['permissions'] ?? null) ? $viewer['permissions'] : [];

        if (
            in_array('*', $permissions, true)
            || in_array('admin.company.manage', $permissions, true)
            || in_array('leave.request.manage.hr', $permissions, true)
        ) {
            return true;
        }

        $roles = is_array($viewer['workforce_roles'] ?? null) ? $viewer['workforce_roles'] : [];

        return in_array('hr', $roles, true) || in_array('hr_assistant', $roles, true);
    }

    public static function canView(array $viewer, array $target): bool
    {
        if (self::hasGlobalVisibility($viewer) || self::sameIdentity($viewer, $target)) {
            return true;
        }

        $viewerLocation = self::locationForProfile($viewer);
        $targetLocation = self::locationForProfile($target);

        return $viewerLocation !== '' && $targetLocation !== '' && $viewerLocation === $targetLocation;
    }

    public static function visibleOptions(array $viewer): array
    {
        $options = self::options();

        if (self::hasGlobalVisibility($viewer)) {
            return $options;
        }

        $location = self::locationForProfile($viewer);

        return $location !== '' && isset($options[$location]) ? [$location => $options[$location]] : [];
    }

    private static function sameIdentity(array $viewer, array $target): bool
    {
        $viewerIdentities = self::identities($viewer);

        if ($viewerIdentities === []) {
            return false;
        }

        return array_intersect($viewerIdentities, self::identities($target)) !== [];
    }

    private static function identities(array $profile): array
    {
        $identities = [];

        foreach (['profile_key', 'email', 'personnel_id'] as $key) {
            $identity = strtolower(trim((string) ($profile[$key] ?? '')));

            if ($identity !== '') {
                $identities[] = $identity;
            }
        }

        return array_values(array_unique($identities));
    }

    private static function normalizeText(string $value): string
    {
        $value = strtr(trim($value), [
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

        return strtolower($value);
    }
}
