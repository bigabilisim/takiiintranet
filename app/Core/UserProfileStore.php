<?php

namespace App\Core;

class UserProfileStore
{
    private const VERSION = 6;
    private const MIN_PASSWORD_LENGTH = 12;
    private const DUMMY_PASSWORD_HASH = '$2y$12$wFZkHqU.tf6ZnE/X/RGyUO7wSXHQRBxa6ImBEAs2rQHNHD5mh5CoG';
    private const STATE_KEY = 'user_profiles';
    private const NO_EMAIL_KEY_PREFIX = 'no-email-';
    private const CSV_COLUMNS = [
        'personnel_id',
        'username',
        'email',
        'first_name',
        'last_name',
        'role',
        'department',
        'location',
        'pdks_id',
        'started_on',
        'employment_type',
        'phone',
        'personal_phone',
        'birth_date',
        'leave_opening_total_days',
        'leave_opening_used_days',
        'leave_opening_remaining_days',
        'leave_opening_snapshot_date',
        'leave_opening_source',
        'national_id',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'education_level',
        'school',
        'faculty',
        'graduation_year',
        'hr_notes',
        'workforce_roles',
        'shift_key',
        'password',
    ];
    private const WORKFORCE_ROLE_CHOICES = [
        'hr',
        'hr_assistant',
        'hr_assistant_antalya',
        'hr_assistant_bursa',
        'manager',
        'shift_planner',
        'weekend_duty',
    ];
    private const DEFAULT_IMPORTED_PERMISSIONS = [
        'module.announcements.access',
        'module.leave.access',
        'module.messages.access',
        'messaging.send',
        'leave.request.create',
        'content.announcement.view',
    ];
    private ?array $cachedData = null;

    public function __construct(
        private readonly array $baseUsers,
        private readonly StateStore $stateStore,
    ) {
        $writeGuard = $this->writeGuard();
        $this->ensureSeeded();
    }

    public function users(): array
    {
        return $this->data()['profiles'];
    }

    public function find(string $email): ?array
    {
        $users = $this->users();
        $identifier = trim($email);

        if (isset($users[$identifier])) {
            return $users[$identifier];
        }

        $email = $this->normalizeEmail($identifier);

        if ($email !== '') {
            foreach ($users as $profile) {
                if (($profile['email'] ?? '') === $email) {
                    return $profile;
                }
            }
        }

        $username = $this->normalizeUsername($identifier);

        if ($username !== '') {
            foreach ($users as $profile) {
                if (hash_equals((string) ($profile['username'] ?? ''), $username)) {
                    return $profile;
                }
            }
        }

        if ($identifier !== '') {
            foreach ($users as $profile) {
                if (hash_equals((string) ($profile['personnel_id'] ?? ''), $identifier)) {
                    return $profile;
                }

                if (hash_equals((string) ($profile['pdks_id'] ?? ''), $identifier)) {
                    return $profile;
                }
            }
        }

        return null;
    }

    private function loginIdentifierMatches(string $identifier, array $user): bool
    {
        $identifier = trim($identifier);
        $profileKey = (string) ($user['profile_key'] ?? '');
        $email = (string) ($user['email'] ?? '');
        $normalizedEmail = $this->normalizeEmail($identifier);
        $username = $this->normalizeUsername($identifier);
        $pdksId = trim((string) ($user['pdks_id'] ?? ''));

        if ($profileKey !== '' && hash_equals($profileKey, $identifier)) {
            return true;
        }

        if ($email !== '' && $normalizedEmail !== '' && hash_equals($email, $normalizedEmail)) {
            return true;
        }

        if ($username !== '' && hash_equals((string) ($user['username'] ?? ''), $username)) {
            return true;
        }

        return $pdksId !== '' && hash_equals($pdksId, $identifier);
    }

    public function canDeleteProfile(string $email): bool
    {
        return !isset($this->baseUsers[$email]) && isset($this->users()[$email]);
    }

    public function verifyCredentials(string $email, string $password): ?array
    {
        if (strlen($password) > 4096) {
            password_verify('', self::DUMMY_PASSWORD_HASH);

            return null;
        }

        $user = $this->find($email);

        if ($user === null) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);

            return null;
        }

        $profileKey = (string) ($user['profile_key'] ?? $email);

        if (!isset($this->baseUsers[$profileKey]) && !$this->loginIdentifierMatches($email, $user)) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);

            return null;
        }

        $passwordHash = (string) ($user['password_hash'] ?? '');
        $basePassword = (string) ($this->baseUsers[$profileKey]['password'] ?? $this->baseUsers[$email]['password'] ?? '');
        $isValid = $passwordHash !== ''
            ? password_verify($password, $passwordHash)
            : ($basePassword !== '' && hash_equals($basePassword, $password));

        if (!$isValid) {
            return null;
        }

        if ($passwordHash !== '' && password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $this->setPasswordForProfileKey($profileKey, $password);
            $user = $this->find($profileKey) ?? $user;
        }

        return $user;
    }

    public function profileForPasswordReset(string $email): ?array
    {
        $email = $this->normalizeEmail($email);

        if ($email === '') {
            return null;
        }

        foreach ($this->users() as $profileKey => $profile) {
            if ($profileKey !== $email && ($profile['email'] ?? '') !== $email) {
                continue;
            }

            if (($profile['email'] ?? '') === '') {
                return null;
            }

            $profile['profile_key'] = (string) $profileKey;

            return $profile;
        }

        return null;
    }

    public function setPasswordForProfileKey(string $profileKey, string $password): bool
    {
        $writeGuard = $this->writeGuard();

        if (strlen($password) < self::MIN_PASSWORD_LENGTH || strlen($password) > 4096) {
            return false;
        }

        $users = $this->users();

        if (!isset($users[$profileKey])) {
            return false;
        }

        $profile = $users[$profileKey];
        $profile['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $profile['password_changed_at'] = date('Y-m-d H:i:s');
        $profile['updated_at'] = date('Y-m-d H:i');

        $data = $this->loadWritableData();
        $data['version'] = self::VERSION;
        $data['profiles'] = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $data['profiles'][$profileKey] = $this->profileForStorage($profile);
        $this->saveData($data);

        return true;
    }

    public function credentialVersionForProfile(string $profileKey): string
    {
        $profile = $this->find($profileKey);

        if ($profile === null) {
            return '';
        }

        $passwordHash = (string) ($profile['password_hash'] ?? '');
        $changedAt = (string) ($profile['password_changed_at'] ?? '');

        if ($passwordHash !== '') {
            return hash('sha256', 'stored|' . $passwordHash . '|' . $changedAt);
        }

        $actualProfileKey = (string) ($profile['profile_key'] ?? $profileKey);
        $basePassword = (string) ($this->baseUsers[$actualProfileKey]['password'] ?? '');
        $secret = (string) (getenv('APP_SESSION_SECRET') ?: 'mytakii-session-version');

        return hash_hmac('sha256', 'base|' . $basePassword, $secret);
    }

    public function setShiftForProfiles(array $profileKeys, string $shiftKey): array
    {
        $writeGuard = $this->writeGuard();
        $profileKeys = array_values(array_unique(array_filter(array_map('strval', $profileKeys))));
        $shiftKey = $this->cleanText($shiftKey, 80);
        $users = $this->users();
        $data = $this->loadWritableData();
        $data['version'] = self::VERSION;
        $data['profiles'] = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $updated = 0;
        $skipped = 0;

        foreach ($profileKeys as $profileKey) {
            if (!isset($users[$profileKey])) {
                $skipped++;
                continue;
            }

            $profile = $users[$profileKey];
            $profile['shift_key'] = $shiftKey;
            $profile['updated_at'] = date('Y-m-d H:i');
            $data['profiles'][$profileKey] = $this->profileForStorage($profile);
            $users[$profileKey] = $profile;
            $updated++;
        }

        if ($updated > 0) {
            $this->saveData($data);
            $currentUser = Session::get('user');

            if (is_array($currentUser)) {
                $currentProfileKey = (string) ($currentUser['profile_key'] ?? $currentUser['email'] ?? '');

                if (isset($users[$currentProfileKey])) {
                    Session::put('user', array_merge($currentUser, $this->sessionProfile($users[$currentProfileKey])));
                }
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    public function createProfile(array $input): array
    {
        $writeGuard = $this->writeGuard();
        $emailInput = array_key_exists('new_email', $input)
            ? trim((string) $input['new_email'])
            : trim((string) ($input['email'] ?? ''));
        $email = $emailInput === '' ? '' : $this->normalizeEmail($emailInput);

        if ($emailInput !== '' && $email === '') {
            return ['ok' => false, 'message' => 'personnel.flash.email_invalid'];
        }

        if ($email !== '' && $this->emailBelongsToAnotherProfile($email, '')) {
            return ['ok' => false, 'message' => 'personnel.flash.email_duplicate'];
        }

        $firstName = $this->cleanText((string) ($input['first_name'] ?? ''), 80);
        $lastName = $this->cleanText((string) ($input['last_name'] ?? ''), 80);
        $role = $this->cleanText((string) ($input['role'] ?? ''), 100);
        $department = $this->cleanText((string) ($input['department'] ?? ''), 100);
        $location = LocationScope::normalize((string) ($input['location'] ?? ''));

        if ($location === '') {
            $location = LocationScope::locationForProfile([
                'department' => $department,
                'shift_key' => (string) ($input['shift_key'] ?? ''),
            ]);
        }

        if ($firstName === '' || $lastName === '' || $role === '' || $department === '') {
            return ['ok' => false, 'message' => 'admin.flash.user_profile_invalid'];
        }

        $workforceRoles = $this->cleanWorkforceRoles($input['workforce_roles'] ?? []);

        if ($this->hasConflictingHrAssistantRoles($workforceRoles)) {
            return ['ok' => false, 'message' => 'personnel.flash.hr_assistant_location_conflict'];
        }

        $workforceRoles = $this->scopeLegacyHrAssistantRole($workforceRoles, $location);

        $usernameInput = trim((string) ($input['username'] ?? ''));
        $username = $usernameInput !== ''
            ? $this->normalizeUsername($usernameInput)
            : $this->generatedUsername($firstName, $lastName, '');

        if (!$this->isValidUsername($username)) {
            return ['ok' => false, 'message' => 'personnel.flash.username_invalid'];
        }

        if ($this->usernameBelongsToAnotherProfile($username, '')) {
            return ['ok' => false, 'message' => 'personnel.flash.username_duplicate'];
        }

        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

        if ($password !== '' && (strlen($password) < self::MIN_PASSWORD_LENGTH || strlen($password) > 4096 || $password !== $passwordConfirmation)) {
            return ['ok' => false, 'message' => 'admin.flash.password_invalid'];
        }

        $profile = [
            'email' => $email,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim($firstName . ' ' . $lastName),
            'role' => $role,
            'department' => $department,
            'location' => $location,
            'pdks_id' => $this->cleanText((string) ($input['pdks_id'] ?? ''), 80),
            'started_on' => $this->cleanDate((string) ($input['started_on'] ?? '')),
            'employment_type' => $this->cleanChoice((string) ($input['employment_type'] ?? 'full_time'), ['full_time', 'part_time', 'contractor', 'intern']) ?: 'full_time',
            'phone' => $this->cleanText((string) ($input['phone'] ?? ''), 40),
            'personal_phone' => $this->cleanText((string) ($input['personal_phone'] ?? ''), 40),
            'birth_date' => $this->cleanDate((string) ($input['birth_date'] ?? '')),
            'leave_opening_total_days' => $this->cleanDecimal((string) ($input['leave_opening_total_days'] ?? '')),
            'leave_opening_used_days' => $this->cleanDecimal((string) ($input['leave_opening_used_days'] ?? '')),
            'leave_opening_remaining_days' => $this->cleanDecimal((string) ($input['leave_opening_remaining_days'] ?? '')),
            'leave_opening_snapshot_date' => $this->cleanDate((string) ($input['leave_opening_snapshot_date'] ?? '')),
            'leave_opening_source' => $this->cleanText((string) ($input['leave_opening_source'] ?? ''), 120),
            'national_id' => $this->cleanText((string) ($input['national_id'] ?? ''), 40),
            'address' => $this->cleanText((string) ($input['address'] ?? ''), 400),
            'emergency_contact_name' => $this->cleanText((string) ($input['emergency_contact_name'] ?? ''), 120),
            'emergency_contact_phone' => $this->cleanText((string) ($input['emergency_contact_phone'] ?? ''), 40),
            'education_level' => $this->cleanChoice((string) ($input['education_level'] ?? ''), ['high_school', 'associate', 'bachelor', 'master', 'doctorate', 'other']),
            'school' => $this->cleanText((string) ($input['school'] ?? ''), 160),
            'faculty' => $this->cleanText((string) ($input['faculty'] ?? ''), 160),
            'graduation_year' => $this->cleanYear((string) ($input['graduation_year'] ?? '')),
            'hr_notes' => $this->cleanText((string) ($input['hr_notes'] ?? ''), 600),
            'workforce_roles' => $workforceRoles,
            'shift_key' => $this->cleanText((string) ($input['shift_key'] ?? ''), 80),
            'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : '',
            'password_changed_at' => $password !== '' ? date('Y-m-d H:i:s') : '',
            'created_at' => date('Y-m-d H:i'),
            'updated_at' => date('Y-m-d H:i'),
        ];

        $data = $this->loadWritableData();
        $data['version'] = self::VERSION;
        $data['profiles'] = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $profileKey = $this->profileKeyForEmail($email, $profile, $data['profiles'], '');
        $profile['personnel_id'] = $this->generatedPersonnelId($profileKey);

        if (isset($data['profiles'][$profileKey])) {
            return ['ok' => false, 'message' => $email !== '' ? 'personnel.flash.email_duplicate' : 'personnel.flash.duplicate'];
        }

        $data['profiles'][$profileKey] = $this->profileForStorage($profile);
        $this->saveData($data);

        return [
            'ok' => true,
            'message' => 'personnel.flash.created',
            'profile_key' => $profileKey,
            'personnel_id' => $profile['personnel_id'],
            'name' => $profile['name'],
            'email' => $email,
            'username' => $username,
        ];
    }

    public function updateProfile(string $email, array $input, bool $syncSession = true): array
    {
        $writeGuard = $this->writeGuard();
        $users = $this->users();

        if (!isset($users[$email])) {
            return ['ok' => false, 'message' => 'admin.flash.user_not_found'];
        }

        $profile = $users[$email];
        $currentEmail = (string) ($profile['email'] ?? '');
        $isBaseProfile = isset($this->baseUsers[$email]);
        $newEmailInput = array_key_exists('new_email', $input)
            ? trim((string) $input['new_email'])
            : $currentEmail;
        $newEmail = $newEmailInput === '' ? '' : $this->normalizeEmail($newEmailInput);

        if ($newEmailInput !== '' && $newEmail === '') {
            return ['ok' => false, 'message' => 'personnel.flash.email_invalid'];
        }

        if ($isBaseProfile && $newEmail !== $currentEmail) {
            return ['ok' => false, 'message' => 'personnel.flash.email_locked'];
        }

        if ($newEmail !== '' && $this->emailBelongsToAnotherProfile($newEmail, $email)) {
            return ['ok' => false, 'message' => 'personnel.flash.email_duplicate'];
        }

        $firstName = $this->cleanText((string) ($input['first_name'] ?? ''), 80);
        $lastName = $this->cleanText((string) ($input['last_name'] ?? ''), 80);
        $role = $this->cleanText((string) ($input['role'] ?? ''), 100);
        $department = $this->cleanText((string) ($input['department'] ?? ''), 100);
        $location = array_key_exists('location', $input)
            ? LocationScope::normalize((string) $input['location'])
            : LocationScope::locationForProfile($profile);

        if ($location === '') {
            $location = LocationScope::locationForProfile([
                'department' => $department,
                'shift_key' => (string) ($input['shift_key'] ?? ($profile['shift_key'] ?? '')),
            ]);
        }

        if ($firstName === '' || $lastName === '' || $role === '' || $department === '') {
            return ['ok' => false, 'message' => 'admin.flash.user_profile_invalid'];
        }

        $workforceRoles = $this->cleanWorkforceRoles($input['workforce_roles'] ?? []);

        if ($this->hasConflictingHrAssistantRoles($workforceRoles)) {
            return ['ok' => false, 'message' => 'personnel.flash.hr_assistant_location_conflict'];
        }

        $workforceRoles = $this->scopeLegacyHrAssistantRole($workforceRoles, $location);

        $usernameInput = array_key_exists('username', $input)
            ? trim((string) $input['username'])
            : (string) ($profile['username'] ?? '');
        $username = $usernameInput !== ''
            ? $this->normalizeUsername($usernameInput)
            : $this->generatedUsername($firstName, $lastName, $email);

        if (!$this->isValidUsername($username)) {
            return ['ok' => false, 'message' => 'personnel.flash.username_invalid'];
        }

        if ($this->usernameBelongsToAnotherProfile($username, $email)) {
            return ['ok' => false, 'message' => 'personnel.flash.username_duplicate'];
        }

        $startedOn = $this->cleanDate((string) ($input['started_on'] ?? ''));
        $birthDate = $this->cleanDate((string) ($input['birth_date'] ?? ''));
        $leaveOpeningSnapshotDate = $this->cleanDate((string) ($input['leave_opening_snapshot_date'] ?? ''));
        $graduationYear = $this->cleanYear((string) ($input['graduation_year'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

        if ($password !== '') {
            if (strlen($password) < self::MIN_PASSWORD_LENGTH || strlen($password) > 4096 || $password !== $passwordConfirmation) {
                return ['ok' => false, 'message' => 'admin.flash.password_invalid'];
            }

            $profile['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $profile['password_changed_at'] = date('Y-m-d H:i:s');
        }

        $profile = array_merge($profile, [
            'email' => $newEmail,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim($firstName . ' ' . $lastName),
            'role' => $role,
            'department' => $department,
            'location' => $location,
            'pdks_id' => $this->cleanText((string) ($input['pdks_id'] ?? ''), 80),
            'started_on' => $startedOn,
            'employment_type' => $this->cleanChoice((string) ($input['employment_type'] ?? ''), ['full_time', 'part_time', 'contractor', 'intern']),
            'phone' => $this->cleanText((string) ($input['phone'] ?? ''), 40),
            'personal_phone' => $this->cleanText((string) ($input['personal_phone'] ?? ''), 40),
            'birth_date' => $birthDate,
            'leave_opening_total_days' => $this->cleanDecimal((string) ($input['leave_opening_total_days'] ?? '')),
            'leave_opening_used_days' => $this->cleanDecimal((string) ($input['leave_opening_used_days'] ?? '')),
            'leave_opening_remaining_days' => $this->cleanDecimal((string) ($input['leave_opening_remaining_days'] ?? '')),
            'leave_opening_snapshot_date' => $leaveOpeningSnapshotDate,
            'leave_opening_source' => $this->cleanText((string) ($input['leave_opening_source'] ?? ''), 120),
            'national_id' => $this->cleanText((string) ($input['national_id'] ?? ''), 40),
            'address' => $this->cleanText((string) ($input['address'] ?? ''), 400),
            'emergency_contact_name' => $this->cleanText((string) ($input['emergency_contact_name'] ?? ''), 120),
            'emergency_contact_phone' => $this->cleanText((string) ($input['emergency_contact_phone'] ?? ''), 40),
            'education_level' => $this->cleanChoice((string) ($input['education_level'] ?? ''), ['high_school', 'associate', 'bachelor', 'master', 'doctorate', 'other']),
            'school' => $this->cleanText((string) ($input['school'] ?? ''), 160),
            'faculty' => $this->cleanText((string) ($input['faculty'] ?? ''), 160),
            'graduation_year' => $graduationYear,
            'hr_notes' => $this->cleanText((string) ($input['hr_notes'] ?? ''), 600),
            'workforce_roles' => $workforceRoles,
            'shift_key' => $this->cleanText((string) ($input['shift_key'] ?? ''), 80),
            'updated_at' => date('Y-m-d H:i'),
        ]);

        $data = $this->loadWritableData();
        $data['profiles'] = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $newProfileKey = $this->profileKeyForEmail($newEmail, $profile, $data['profiles'], $email);

        unset($data['profiles'][$email]);
        $data['profiles'][$newProfileKey] = $this->profileForStorage($profile);
        $this->saveData($data);

        $currentUser = Session::get('user');

        if ($syncSession && is_array($currentUser) && ($currentUser['email'] ?? '') === $email) {
            $currentUser = array_merge($currentUser, $this->sessionProfile($profile));
            $currentUser['email'] = $newProfileKey;
            Session::put('user', $currentUser);
        }

        return [
            'ok' => true,
            'message' => 'admin.flash.user_profile_saved',
            'profile_key' => $newProfileKey,
            'old_profile_key' => $email,
            'new_profile_key' => $newProfileKey,
            'old_email' => $currentEmail,
            'new_email' => $newEmail,
        ];
    }

    public function syncSessionAfterProfileUpdate(string $oldProfileKey, string $newProfileKey): void
    {
        $currentUser = Session::get('user');

        if (!is_array($currentUser) || (string) ($currentUser['email'] ?? '') !== $oldProfileKey) {
            return;
        }

        $profile = $this->find($newProfileKey);

        if ($profile === null) {
            return;
        }

        $currentUser = array_merge($currentUser, $this->sessionProfile($profile));
        $currentUser['email'] = $newProfileKey;
        Session::put('user', $currentUser);
    }

    public function deleteProfile(string $email): array
    {
        $writeGuard = $this->writeGuard();

        if (!$this->canDeleteProfile($email)) {
            return ['ok' => false, 'message' => 'personnel.flash.delete_blocked'];
        }

        $data = $this->loadWritableData();

        if (!is_array($data['profiles'] ?? null) || !isset($data['profiles'][$email])) {
            return ['ok' => false, 'message' => 'admin.flash.user_not_found'];
        }

        unset($data['profiles'][$email]);
        $this->saveData($data);

        return ['ok' => true, 'message' => 'personnel.flash.deleted'];
    }

    public function exportProfilesCsv(?array $profiles = null): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');

        foreach ($profiles ?? $this->users() as $profile) {
            $row = [];

            foreach (self::CSV_COLUMNS as $column) {
                $row[] = $column === 'password' ? '' : $this->csvExportValue($profile[$column] ?? '');
            }

            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . $csv;
    }

    public function exportProfilesXlsx(?array $profiles = null): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }

        $path = tempnam(sys_get_temp_dir(), 'personnel-xlsx-');

        if ($path === false) {
            return '';
        }

        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            return '';
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->xlsxStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheet($profiles ?? $this->users()));
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    public function importProfilesCsv(string $path): array
    {
        $writeGuard = $this->writeGuard();

        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'message' => 'admin.flash.personnel_import_failed'];
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['ok' => false, 'message' => 'admin.flash.personnel_import_failed'];
        }

        $firstLine = fgets($handle);

        if ($firstLine === false) {
            fclose($handle);

            return ['ok' => false, 'message' => 'admin.flash.personnel_import_failed'];
        }

        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $headers = str_getcsv($firstLine, $delimiter, '"', '');
        $columnMap = [];

        foreach ($headers as $index => $header) {
            $column = $this->canonicalCsvColumn((string) $header);

            if ($column !== '') {
                $columnMap[$index] = $column;
            }
        }

        if (!in_array('email', $columnMap, true)) {
            fclose($handle);

            return ['ok' => false, 'message' => 'admin.flash.personnel_import_failed'];
        }

        $profiles = $this->users();
        $data = $this->loadWritableData();
        $data['version'] = self::VERSION;
        $data['profiles'] = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $record = [];

            foreach ($columnMap as $index => $column) {
                $record[$column] = trim((string) ($row[$index] ?? ''));
            }

            $emailInput = strtolower($this->cleanText((string) ($record['email'] ?? ''), 160));
            $email = $emailInput === '' ? '' : $this->normalizeEmail($emailInput);

            if ($emailInput !== '' && $email === '') {
                $skipped++;
                continue;
            }

            $nameParts = $this->splitName((string) ($record['name'] ?? ''));
            $profileKey = $email !== ''
                ? $email
                : $this->profileKeyForEmail('', [
                    'pdks_id' => (string) ($record['pdks_id'] ?? ''),
                    'name' => trim((string) ($record['name'] ?? '')),
                    'first_name' => (string) ($record['first_name'] ?? $nameParts['first_name']),
                    'last_name' => (string) ($record['last_name'] ?? $nameParts['last_name']),
                ], $data['profiles'], '');
            $existing = $profiles[$profileKey] ?? [];
            $baseUser = $this->baseUsers[$profileKey] ?? $this->importedBaseUser($profileKey, $existing);
            $profile = $this->mergeProfile($profileKey, $baseUser, $existing);
            $firstName = $this->importText($record, 'first_name', $profile, 80, $nameParts['first_name']);
            $lastName = $this->importText($record, 'last_name', $profile, 80, $nameParts['last_name']);
            $role = $this->importText($record, 'role', $profile, 100);
            $department = $this->importText($record, 'department', $profile, 100);
            $location = array_key_exists('location', $record)
                ? LocationScope::normalize((string) ($record['location'] ?? ''))
                : LocationScope::locationForProfile($profile);

            if ($location === '') {
                $location = LocationScope::locationForProfile([
                    'department' => $department,
                    'shift_key' => (string) ($record['shift_key'] ?? ($profile['shift_key'] ?? '')),
                ]);
            }

            if ($firstName === '' || $lastName === '' || $role === '' || $department === '') {
                $skipped++;
                continue;
            }

            $usernameInput = array_key_exists('username', $record)
                ? trim((string) $record['username'])
                : (string) ($profile['username'] ?? '');
            $username = $usernameInput !== ''
                ? $this->normalizeUsername($usernameInput)
                : $this->generatedUsername($firstName, $lastName, $profileKey, $profiles);

            if (!$this->isValidUsername($username) || $this->usernameBelongsToAnotherProfileIn($username, $profileKey, $profiles)) {
                $skipped++;
                continue;
            }

            $profile = array_merge($profile, [
                'email' => $email,
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim($firstName . ' ' . $lastName),
                'role' => $role,
                'department' => $department,
                'location' => $location,
                'pdks_id' => $this->importText($record, 'pdks_id', $profile, 80),
                'started_on' => $this->importDate($record, 'started_on', $profile),
                'employment_type' => $this->importChoice($record, 'employment_type', $profile, ['full_time', 'part_time', 'contractor', 'intern']),
                'phone' => $this->importText($record, 'phone', $profile, 40),
                'personal_phone' => $this->importText($record, 'personal_phone', $profile, 40),
                'birth_date' => $this->importDate($record, 'birth_date', $profile),
                'leave_opening_total_days' => $this->importDecimal($record, 'leave_opening_total_days', $profile),
                'leave_opening_used_days' => $this->importDecimal($record, 'leave_opening_used_days', $profile),
                'leave_opening_remaining_days' => $this->importDecimal($record, 'leave_opening_remaining_days', $profile),
                'leave_opening_snapshot_date' => $this->importDate($record, 'leave_opening_snapshot_date', $profile),
                'leave_opening_source' => $this->importText($record, 'leave_opening_source', $profile, 120),
                'national_id' => $this->importText($record, 'national_id', $profile, 40),
                'address' => $this->importText($record, 'address', $profile, 400),
                'emergency_contact_name' => $this->importText($record, 'emergency_contact_name', $profile, 120),
                'emergency_contact_phone' => $this->importText($record, 'emergency_contact_phone', $profile, 40),
                'education_level' => $this->importChoice($record, 'education_level', $profile, ['high_school', 'associate', 'bachelor', 'master', 'doctorate', 'other']),
                'school' => $this->importText($record, 'school', $profile, 160),
                'faculty' => $this->importText($record, 'faculty', $profile, 160),
                'graduation_year' => $this->importYear($record, 'graduation_year', $profile),
                'hr_notes' => $this->importText($record, 'hr_notes', $profile, 600),
                'workforce_roles' => array_key_exists('workforce_roles', $record)
                    ? $this->cleanWorkforceRoles($record['workforce_roles'] ?? '')
                    : $this->cleanWorkforceRoles($profile['workforce_roles'] ?? []),
                'shift_key' => $this->importText($record, 'shift_key', $profile, 80),
                'updated_at' => date('Y-m-d H:i'),
            ]);

            $password = (string) ($record['password'] ?? '');

            if ($password !== '') {
                if (strlen($password) < self::MIN_PASSWORD_LENGTH || strlen($password) > 4096) {
                    $skipped++;
                    continue;
                }

                $profile['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $profile['password_changed_at'] = date('Y-m-d H:i:s');
            }

            $data['profiles'][$profileKey] = $this->profileForStorage($profile);

            if (isset($profiles[$profileKey])) {
                $updated++;
            } else {
                $created++;
            }

            $profiles[$profileKey] = $profile;
        }

        fclose($handle);

        if ($created + $updated === 0) {
            return ['ok' => false, 'message' => 'admin.flash.personnel_import_failed', 'skipped' => $skipped];
        }

        $this->saveData($data);

        return [
            'ok' => true,
            'message' => 'admin.flash.personnel_imported',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function ensureSeeded(): void
    {
        $this->data();
    }

    private function data(): array
    {
        if ($this->cachedData !== null && !$this->stateStore->hasActiveWrite(self::STATE_KEY)) {
            return $this->cachedData;
        }

        $data = $this->loadWritableData();
        $dirty = false;

        if (!isset($data['profiles']) || !is_array($data['profiles'])) {
            $data = ['version' => self::VERSION, 'profiles' => []];
            $dirty = true;
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            $data['version'] = self::VERSION;
            $dirty = true;
        }

        foreach ($this->baseUsers as $email => $baseUser) {
            $existing = is_array($data['profiles'][$email] ?? null) ? $data['profiles'][$email] : [];
            $profile = $this->mergeProfile($email, $baseUser, $existing);

            if (($data['profiles'][$email] ?? null) != $this->profileForStorage($profile)) {
                $data['profiles'][$email] = $this->profileForStorage($profile);
                $dirty = true;
            }
        }

        foreach (array_keys($data['profiles']) as $email) {
            if (isset($this->baseUsers[$email])) {
                continue;
            }

            $existing = is_array($data['profiles'][$email] ?? null) ? $data['profiles'][$email] : [];

            if (!$this->isValidProfileKey($email, $existing)) {
                unset($data['profiles'][$email]);
                $dirty = true;
                continue;
            }

            $profile = $this->mergeProfile($email, $this->importedBaseUser($email, $existing), $existing);

            if (($data['profiles'][$email] ?? null) != $this->profileForStorage($profile)) {
                $data['profiles'][$email] = $this->profileForStorage($profile);
                $dirty = true;
            }
        }

        if ($this->ensureUniqueUsernames($data['profiles'])) {
            $dirty = true;
        }

        if ($dirty) {
            $this->saveData($data);
        }

        $profiles = [];

        $emails = array_values(array_unique(array_merge(
            array_keys($this->baseUsers),
            array_keys($data['profiles'])
        )));

        foreach ($emails as $email) {
            $stored = is_array($data['profiles'][$email] ?? null) ? $data['profiles'][$email] : [];
            $baseUser = $this->baseUsers[$email] ?? $this->importedBaseUser($email, $stored);
            $profiles[$email] = $this->mergeProfile($email, $baseUser, $stored);
        }

        $data = ['version' => self::VERSION, 'profiles' => $profiles];

        if (!$this->stateStore->hasActiveWrite(self::STATE_KEY)) {
            $this->cachedData = $data;
        }

        return $data;
    }

    private function mergeProfile(string $email, array $baseUser, array $stored): array
    {
        $nameParts = $this->splitName((string) ($baseUser['name'] ?? $email));
        $isBaseProfile = isset($this->baseUsers[$email]);
        $emailValue = $this->normalizeEmail((string) ($stored['email'] ?? ''));

        if ($emailValue === '' && ($isBaseProfile || !array_key_exists('email', $stored)) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailValue = $email;
        }

        $profile = array_merge([
            'profile_key' => $email,
            'personnel_id' => $this->generatedPersonnelId($email),
            'email' => $email,
            'username' => '',
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'name' => (string) ($baseUser['name'] ?? $email),
            'role' => (string) ($baseUser['role'] ?? ''),
            'department' => (string) ($baseUser['department'] ?? ''),
            'location' => '',
            'pdks_id' => '',
            'started_on' => (string) ($baseUser['started_on'] ?? ''),
            'employment_type' => 'full_time',
            'phone' => '',
            'personal_phone' => '',
            'birth_date' => '',
            'leave_opening_total_days' => 0,
            'leave_opening_used_days' => 0,
            'leave_opening_remaining_days' => 0,
            'leave_opening_snapshot_date' => '',
            'leave_opening_source' => '',
            'national_id' => '',
            'address' => '',
            'emergency_contact_name' => '',
            'emergency_contact_phone' => '',
            'education_level' => '',
            'school' => '',
            'faculty' => '',
            'graduation_year' => '',
            'hr_notes' => '',
            'workforce_roles' => is_array($baseUser['workforce_roles'] ?? null)
                ? $baseUser['workforce_roles']
                : [],
            'shift_key' => '',
            'password_hash' => '',
            'password_changed_at' => '',
            'created_at' => date('Y-m-d H:i'),
            'updated_at' => date('Y-m-d H:i'),
        ], $stored);

        $profile['profile_key'] = $email;
        $profile['personnel_id'] = $this->cleanPersonnelId((string) ($profile['personnel_id'] ?? ''));

        if ($profile['personnel_id'] === '') {
            $profile['personnel_id'] = $this->generatedPersonnelId($email);
        }

        $profile['email'] = $emailValue;
        $profile['location'] = LocationScope::locationForProfile($profile);
        $profile['workforce_roles'] = $this->scopeLegacyHrAssistantRole(
            $profile['workforce_roles'] ?? [],
            $profile['location']
        );
        $profile['permissions'] = $baseUser['permissions'] ?? [];
        if (($profile['name'] ?? '') === '') {
            $profile['name'] = trim((string) $profile['first_name'] . ' ' . (string) $profile['last_name']);
        }

        return $profile;
    }

    private function importedBaseUser(string $email, array $stored): array
    {
        $name = trim((string) ($stored['name'] ?? ''));

        if ($name === '') {
            $name = trim((string) ($stored['first_name'] ?? '') . ' ' . (string) ($stored['last_name'] ?? ''));
        }

        return [
            'password' => '',
            'name' => $name !== '' ? $name : $email,
            'role' => (string) ($stored['role'] ?? 'Employee'),
            'department' => (string) ($stored['department'] ?? 'General'),
            'started_on' => (string) ($stored['started_on'] ?? ''),
            'permissions' => self::DEFAULT_IMPORTED_PERMISSIONS,
        ];
    }

    private function profileForStorage(array $profile): array
    {
        unset($profile['profile_key'], $profile['permissions'], $profile['password']);

        return $profile;
    }

    private function sessionProfile(array $profile): array
    {
        return [
            'personnel_id' => (string) ($profile['personnel_id'] ?? ''),
            'username' => (string) ($profile['username'] ?? ''),
            'name' => (string) ($profile['name'] ?? ''),
            'role' => (string) ($profile['role'] ?? ''),
            'department' => (string) ($profile['department'] ?? ''),
            'location' => LocationScope::locationForProfile($profile),
            'started_on' => (string) ($profile['started_on'] ?? ''),
            'birth_date' => (string) ($profile['birth_date'] ?? ''),
            'leave_opening_total_days' => (float) ($profile['leave_opening_total_days'] ?? 0),
            'leave_opening_used_days' => (float) ($profile['leave_opening_used_days'] ?? 0),
            'leave_opening_remaining_days' => (float) ($profile['leave_opening_remaining_days'] ?? 0),
            'leave_opening_snapshot_date' => (string) ($profile['leave_opening_snapshot_date'] ?? ''),
            'leave_opening_source' => (string) ($profile['leave_opening_source'] ?? ''),
            'shift_key' => (string) ($profile['shift_key'] ?? ''),
        ];
    }

    private function canonicalCsvColumn(string $header): string
    {
        $header = trim(str_replace("\xEF\xBB\xBF", '', $header));
        $header = strtr($header, [
            'İ' => 'I',
            'ı' => 'i',
            'Ş' => 'S',
            'ş' => 's',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ü' => 'U',
            'ü' => 'u',
            'Ö' => 'O',
            'ö' => 'o',
            'Ç' => 'C',
            'ç' => 'c',
        ]);
        $normalized = strtolower($header);
        $normalized = trim(preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '', '_');
        $aliases = [
            'mail' => 'email',
            'e_posta' => 'email',
            'eposta' => 'email',
            'kullanici_adi' => 'username',
            'kullaniciadi' => 'username',
            'username' => 'username',
            'ad' => 'first_name',
            'adi' => 'first_name',
            'first_name' => 'first_name',
            'firstname' => 'first_name',
            'soyad' => 'last_name',
            'soyadi' => 'last_name',
            'last_name' => 'last_name',
            'lastname' => 'last_name',
            'ad_soyad' => 'name',
            'ad_soyadi' => 'name',
            'name' => 'name',
            'unvan' => 'role',
            'title' => 'role',
            'gorev' => 'role',
            'departman' => 'department',
            'bolum' => 'department',
            'lokasyon' => 'location',
            'location' => 'location',
            'tesis' => 'location',
            'pdks' => 'pdks_id',
            'pdks_id' => 'pdks_id',
            'pdksid' => 'pdks_id',
            'sicil_no' => 'pdks_id',
            'personel_no' => 'pdks_id',
            'ise_giris_tarihi' => 'started_on',
            'start_date' => 'started_on',
            'started_on' => 'started_on',
            'calisma_tipi' => 'employment_type',
            'employment_type' => 'employment_type',
            'telefon' => 'phone',
            'is_telefonu' => 'phone',
            'phone' => 'phone',
            'kisisel_telefon' => 'personal_phone',
            'personal_phone' => 'personal_phone',
            'dogum_tarihi' => 'birth_date',
            'birth_date' => 'birth_date',
            'toplam_yillik_izin' => 'leave_opening_total_days',
            'total_annual_leave' => 'leave_opening_total_days',
            'leave_opening_total_days' => 'leave_opening_total_days',
            'kullanilan_izin' => 'leave_opening_used_days',
            'used_annual_leave' => 'leave_opening_used_days',
            'used_leave' => 'leave_opening_used_days',
            'leave_opening_used_days' => 'leave_opening_used_days',
            'kalan_izin' => 'leave_opening_remaining_days',
            'accumulated_annual_leave' => 'leave_opening_remaining_days',
            'remaining_leave' => 'leave_opening_remaining_days',
            'leave_opening_remaining_days' => 'leave_opening_remaining_days',
            'izin_bakiye_tarihi' => 'leave_opening_snapshot_date',
            'leave_opening_snapshot_date' => 'leave_opening_snapshot_date',
            'izin_kaynak' => 'leave_opening_source',
            'leave_opening_source' => 'leave_opening_source',
            'kimlik_no' => 'national_id',
            'tc_kimlik_no' => 'national_id',
            'national_id' => 'national_id',
            'adres' => 'address',
            'address' => 'address',
            'acil_durum_kisisi' => 'emergency_contact_name',
            'emergency_contact_name' => 'emergency_contact_name',
            'acil_durum_telefonu' => 'emergency_contact_phone',
            'emergency_contact_phone' => 'emergency_contact_phone',
            'egitim_durumu' => 'education_level',
            'education_level' => 'education_level',
            'okul' => 'school',
            'school' => 'school',
            'fakulte' => 'faculty',
            'bolum_fakulte' => 'faculty',
            'faculty' => 'faculty',
            'mezuniyet_yili' => 'graduation_year',
            'graduation_year' => 'graduation_year',
            'ik_notlari' => 'hr_notes',
            'hr_notes' => 'hr_notes',
            'gorev_atamalari' => 'workforce_roles',
            'gorevler' => 'workforce_roles',
            'workforce_roles' => 'workforce_roles',
            'vardiya' => 'shift_key',
            'shift' => 'shift_key',
            'shift_key' => 'shift_key',
            'vardiya_kodu' => 'shift_key',
            'sifre' => 'password',
            'password' => 'password',
        ];
        $column = $aliases[$normalized] ?? $normalized;

        return in_array($column, array_merge(self::CSV_COLUMNS, ['name']), true) ? $column : '';
    }

    private function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function importText(array $record, string $column, array $profile, int $maxLength, string $fallback = ''): string
    {
        $value = trim((string) ($record[$column] ?? ''));

        if ($value !== '') {
            return $this->cleanText($value, $maxLength);
        }

        if ($fallback !== '') {
            return $this->cleanText($fallback, $maxLength);
        }

        return $this->cleanText((string) ($profile[$column] ?? ''), $maxLength);
    }

    private function importDate(array $record, string $column, array $profile): string
    {
        $value = trim((string) ($record[$column] ?? ''));

        return $value !== '' ? $this->cleanDate($value) : (string) ($profile[$column] ?? '');
    }

    private function importYear(array $record, string $column, array $profile): string
    {
        $value = trim((string) ($record[$column] ?? ''));

        return $value !== '' ? $this->cleanYear($value) : (string) ($profile[$column] ?? '');
    }

    private function importDecimal(array $record, string $column, array $profile): float
    {
        $value = trim((string) ($record[$column] ?? ''));

        return $value !== '' ? $this->cleanDecimal($value) : (float) ($profile[$column] ?? 0);
    }

    private function importChoice(array $record, string $column, array $profile, array $choices): string
    {
        $value = trim((string) ($record[$column] ?? ''));

        return $value !== '' ? $this->cleanChoice($value, $choices) : (string) ($profile[$column] ?? '');
    }

    private function xlsxSheet(array $profiles): string
    {
        $rows = [self::CSV_COLUMNS];

        foreach ($profiles as $profile) {
            $row = [];

            foreach (self::CSV_COLUMNS as $column) {
                $row[] = $column === 'password' ? '' : $this->exportValue($profile[$column] ?? '');
            }

            $rows[] = $row;
        }

        $sheetRows = [];

        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $cells = [];

            foreach ($row as $columnIndex => $value) {
                $cell = $this->xlsxColumnName($columnIndex + 1) . $rowNumber;
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells[] = '<c r="' . $cell . '" t="inlineStr"' . $style . '><is><t>' . $this->xml($value) . '</t></is></c>';
            }

            $sheetRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $this->xlsxColumnName(count(self::CSV_COLUMNS)) . count($rows) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $this->xlsxColumns() . '</cols>'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
            . '</worksheet>';
    }

    private function xlsxColumns(): string
    {
        $columns = [];

        for ($index = 1; $index <= count(self::CSV_COLUMNS); $index++) {
            $width = $index === 1 ? 28 : 18;
            $columns[] = '<col min="' . $index . '" max="' . $index . '" width="' . $width . '" customWidth="1"/>';
        }

        return implode('', $columns);
    }

    private function xlsxColumnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function xlsxContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Personnel" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function xlsxStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function loadWritableData(): array
    {
        return $this->stateStore->read(self::STATE_KEY, $this->dataPath());
    }

    private function saveData(array $data): void
    {
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), $data);
        $this->cachedData = null;
    }

    private function writeGuard(): StateWriteGuard
    {
        $this->cachedData = null;

        return $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath());
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/user-profiles.json';
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) <= 1) {
            return ['first_name' => $name, 'last_name' => ''];
        }

        $lastName = array_pop($parts);

        return [
            'first_name' => implode(' ', $parts),
            'last_name' => (string) $lastName,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower($this->cleanText($email, 160));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function normalizeUsername(string $username): string
    {
        $username = strtr(trim($username), [
            'İ' => 'I',
            'ı' => 'i',
            'Ş' => 'S',
            'ş' => 's',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ü' => 'U',
            'ü' => 'u',
            'Ö' => 'O',
            'ö' => 'o',
            'Ç' => 'C',
            'ç' => 'c',
            'Ä' => 'A',
            'ä' => 'a',
            'ẞ' => 'SS',
            'ß' => 'ss',
        ]);

        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $username);

            if (is_string($transliterated) && $transliterated !== '') {
                $username = $transliterated;
            }
        }

        $username = strtolower($username);
        $username = preg_replace('/[^a-z0-9]+/', '', $username) ?? '';

        return substr($username, 0, 40);
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9]{3,40}$/', $username) === 1;
    }

    private function generatedUsername(
        string $firstName,
        string $lastName,
        string $currentProfileKey,
        ?array $profiles = null,
    ): string {
        $base = $this->normalizeUsername($firstName . $lastName);

        if (!$this->isValidUsername($base)) {
            $base = 'personel';
        }

        return $this->uniqueUsername($base, $currentProfileKey, $profiles ?? $this->users());
    }

    private function usernameBelongsToAnotherProfile(string $username, string $currentProfileKey): bool
    {
        return $this->usernameBelongsToAnotherProfileIn($username, $currentProfileKey, $this->users());
    }

    private function usernameBelongsToAnotherProfileIn(string $username, string $currentProfileKey, array $profiles): bool
    {
        foreach ($profiles as $profileKey => $profile) {
            if ((string) $profileKey === $currentProfileKey) {
                continue;
            }

            $existing = $this->normalizeUsername((string) ($profile['username'] ?? ''));

            if ($existing !== '' && hash_equals($existing, $username)) {
                return true;
            }
        }

        return false;
    }

    private function uniqueUsername(string $base, string $currentProfileKey, array $profiles): string
    {
        $candidate = substr($base, 0, 40);
        $suffix = 2;

        while ($this->usernameBelongsToAnotherProfileIn($candidate, $currentProfileKey, $profiles)) {
            $suffixText = (string) $suffix;
            $candidate = substr($base, 0, 40 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function ensureUniqueUsernames(array &$profiles): bool
    {
        $dirty = false;
        $claimed = [];

        foreach ($profiles as &$profile) {
            if (!is_array($profile)) {
                continue;
            }

            $username = $this->normalizeUsername((string) ($profile['username'] ?? ''));

            if (!$this->isValidUsername($username) || isset($claimed[$username])) {
                $base = $this->normalizeUsername(
                    (string) ($profile['first_name'] ?? '') . (string) ($profile['last_name'] ?? '')
                );

                if (!$this->isValidUsername($base)) {
                    $base = 'personel';
                }

                $username = substr($base, 0, 40);
                $suffix = 2;

                while (isset($claimed[$username])) {
                    $suffixText = (string) $suffix;
                    $username = substr($base, 0, 40 - strlen($suffixText)) . $suffixText;
                    $suffix++;
                }
            }

            if (($profile['username'] ?? '') !== $username) {
                $profile['username'] = $username;
                $dirty = true;
            }

            $claimed[$username] = true;
        }

        unset($profile);

        return $dirty;
    }

    private function emailBelongsToAnotherProfile(string $email, string $currentProfileKey): bool
    {
        foreach ($this->users() as $profileKey => $profile) {
            if ($profileKey === $currentProfileKey) {
                continue;
            }

            if ($profileKey === $email || ($profile['email'] ?? '') === $email) {
                return true;
            }
        }

        return false;
    }

    private function profileKeyForEmail(string $email, array $profile, array $profiles, string $currentProfileKey): string
    {
        if ($email !== '') {
            return $email;
        }

        if ($this->isNoEmailProfileKey($currentProfileKey)) {
            return $currentProfileKey;
        }

        $base = self::NO_EMAIL_KEY_PREFIX . $this->slugForProfile($profile);
        $key = $base;
        $index = 2;

        while (isset($profiles[$key]) && $key !== $currentProfileKey) {
            $key = $base . '-' . $index;
            $index++;
        }

        return $key;
    }

    private function generatedPersonnelId(string $profileKey): string
    {
        return 'PER-' . strtoupper(substr(hash('sha256', 'takii-personnel|' . $profileKey), 0, 16));
    }

    private function cleanPersonnelId(string $value): string
    {
        $value = strtoupper(trim($value));

        return preg_match('/^PER-[A-F0-9]{16}$/', $value) === 1 ? $value : '';
    }

    private function isValidProfileKey(string $profileKey, array $profile): bool
    {
        if (filter_var($profileKey, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return $this->isNoEmailProfileKey($profileKey) && trim((string) ($profile['name'] ?? '')) !== '';
    }

    private function isNoEmailProfileKey(string $profileKey): bool
    {
        return str_starts_with($profileKey, self::NO_EMAIL_KEY_PREFIX);
    }

    private function slugForProfile(array $profile): string
    {
        $seed = trim((string) ($profile['pdks_id'] ?? ''));

        if ($seed === '') {
            $seed = trim((string) ($profile['name'] ?? ''));
        }

        if ($seed === '') {
            $seed = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
        }

        $seed = strtr($seed, [
            'İ' => 'I',
            'ı' => 'i',
            'Ş' => 'S',
            'ş' => 's',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ü' => 'U',
            'ü' => 'u',
            'Ö' => 'O',
            'ö' => 'o',
            'Ç' => 'C',
            'ç' => 'c',
        ]);
        $seed = strtolower($seed);
        $seed = trim(preg_replace('/[^a-z0-9]+/', '-', $seed) ?? '', '-');

        return substr($seed !== '' ? $seed : 'personel', 0, 80);
    }

    private function cleanText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return substr($value, 0, $maxLength);
    }

    private function cleanDate(string $date): string
    {
        $date = trim($date);

        if ($date === '') {
            return '';
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date ? $date : '';
    }

    private function cleanYear(string $year): string
    {
        $year = trim($year);

        if ($year === '') {
            return '';
        }

        return preg_match('/^(19|20)\d{2}$/', $year) === 1 ? $year : '';
    }

    private function cleanDecimal(string $value): float
    {
        $value = str_replace(',', '.', trim($value));

        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, round((float) $value, 2));
    }

    private function cleanChoice(string $value, array $choices): string
    {
        return in_array($value, $choices, true) ? $value : '';
    }

    private function cleanWorkforceRoles(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/[\s,;|]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $roles = [];

        foreach ($items ?: [] as $item) {
            $role = $this->normalizeWorkforceRole((string) $item);

            if ($role === '') {
                continue;
            }

            $roles[$role] = $role;
        }

        return array_values($roles);
    }

    private function normalizeWorkforceRole(string $role): string
    {
        $role = strtolower(trim($role));
        $role = strtr($role, [
            'İ' => 'i',
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
        $role = preg_replace('/[^a-z0-9]+/', '_', $role) ?: '';
        $role = trim($role, '_');
        $aliases = [
            'ik' => 'hr',
            'hr' => 'hr',
            'human_resources' => 'hr',
            'insan_kaynaklari' => 'hr',
            'ik_asistani' => 'hr_assistant',
            'hr_assistant' => 'hr_assistant',
            'human_resources_assistant' => 'hr_assistant',
            'insan_kaynaklari_asistani' => 'hr_assistant',
            'ik_asistani_antalya' => 'hr_assistant_antalya',
            'hr_assistant_antalya' => 'hr_assistant_antalya',
            'insan_kaynaklari_asistani_antalya' => 'hr_assistant_antalya',
            'ik_asistani_bursa' => 'hr_assistant_bursa',
            'hr_assistant_bursa' => 'hr_assistant_bursa',
            'insan_kaynaklari_asistani_bursa' => 'hr_assistant_bursa',
            'manager' => 'manager',
            'menajer' => 'manager',
            'mudur' => 'manager',
            'yonetici' => 'manager',
            'shift_planner' => 'shift_planner',
            'vardiya_planlayici' => 'shift_planner',
            'nobet_planlayici' => 'shift_planner',
            'weekend_duty' => 'weekend_duty',
            'hafta_sonu_nobetcisi' => 'weekend_duty',
            'haftasonu_nobetcisi' => 'weekend_duty',
            'nobetci' => 'weekend_duty',
        ];
        $role = $aliases[$role] ?? $role;

        return in_array($role, self::WORKFORCE_ROLE_CHOICES, true) ? $role : '';
    }

    private function scopeLegacyHrAssistantRole(mixed $value, string $location): array
    {
        $roles = $this->cleanWorkforceRoles($value);

        if (!in_array('hr_assistant', $roles, true)) {
            return $roles;
        }

        $roles = array_values(array_diff($roles, ['hr_assistant']));

        if (array_intersect($roles, ['hr_assistant_antalya', 'hr_assistant_bursa']) !== []) {
            return $roles;
        }

        $scopedRole = match (LocationScope::normalize($location)) {
            LocationScope::ANTALYA => 'hr_assistant_antalya',
            LocationScope::BURSA => 'hr_assistant_bursa',
            default => 'hr_assistant',
        };
        $roles[] = $scopedRole;

        return array_values(array_unique($roles));
    }

    private function hasConflictingHrAssistantRoles(array $roles): bool
    {
        return count(array_intersect($roles, ['hr_assistant_antalya', 'hr_assistant_bursa'])) > 1;
    }

    private function exportValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode('|', array_map('strval', $value));
        }

        return (string) $value;
    }

    private function csvExportValue(mixed $value): string
    {
        $value = $this->exportValue($value);

        if (preg_match('/\A[\x00-\x20]*[=+\-@]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }
}
