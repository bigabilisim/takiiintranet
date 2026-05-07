<?php

namespace App\Core;

class UserProfileStore
{
    private const VERSION = 1;
    private const NO_EMAIL_KEY_PREFIX = 'no-email-';
    private const CSV_COLUMNS = [
        'email',
        'first_name',
        'last_name',
        'role',
        'department',
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
        'password',
    ];
    private const DEFAULT_IMPORTED_PERMISSIONS = [
        'module.announcements.access',
        'module.leave.access',
        'module.messages.access',
        'messaging.send',
        'leave.request.create',
        'content.announcement.view',
    ];

    public function __construct(
        private readonly array $baseUsers,
    ) {
        $this->ensureSeeded();
    }

    public function users(): array
    {
        return $this->data()['profiles'];
    }

    public function find(string $email): ?array
    {
        $users = $this->users();

        if (isset($users[$email])) {
            return $users[$email];
        }

        $email = $this->normalizeEmail($email);

        if ($email === '') {
            return null;
        }

        foreach ($users as $profile) {
            if (($profile['email'] ?? '') === $email) {
                return $profile;
            }
        }

        return null;
    }

    public function canDeleteProfile(string $email): bool
    {
        return !isset($this->baseUsers[$email]) && isset($this->users()[$email]);
    }

    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->find($email);

        if ($user === null) {
            return null;
        }

        $profileKey = (string) ($user['profile_key'] ?? $email);

        if (!isset($this->baseUsers[$profileKey]) && ($user['email'] ?? '') !== $email) {
            return null;
        }

        $passwordHash = (string) ($user['password_hash'] ?? '');
        $basePassword = (string) ($this->baseUsers[$profileKey]['password'] ?? $this->baseUsers[$email]['password'] ?? '');
        $isValid = $passwordHash !== ''
            ? password_verify($password, $passwordHash)
            : ($basePassword !== '' && hash_equals($basePassword, $password));

        return $isValid ? $user : null;
    }

    public function createProfile(array $input): array
    {
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

        if ($firstName === '' || $lastName === '' || $role === '' || $department === '') {
            return ['ok' => false, 'message' => 'admin.flash.user_profile_invalid'];
        }

        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

        if ($password !== '' && (strlen($password) < 6 || $password !== $passwordConfirmation)) {
            return ['ok' => false, 'message' => 'admin.flash.password_invalid'];
        }

        $profile = [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim($firstName . ' ' . $lastName),
            'role' => $role,
            'department' => $department,
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
            'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : '',
            'created_at' => date('Y-m-d H:i'),
            'updated_at' => date('Y-m-d H:i'),
        ];

        $data = $this->loadWritableData();
        $data['version'] = self::VERSION;
        $data['profiles'] = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $profileKey = $this->profileKeyForEmail($email, $profile, $data['profiles'], '');

        if (isset($data['profiles'][$profileKey])) {
            return ['ok' => false, 'message' => $email !== '' ? 'personnel.flash.email_duplicate' : 'personnel.flash.duplicate'];
        }

        $data['profiles'][$profileKey] = $this->profileForStorage($profile);
        $this->saveData($data);

        return [
            'ok' => true,
            'message' => 'personnel.flash.created',
            'profile_key' => $profileKey,
            'name' => $profile['name'],
            'email' => $email,
        ];
    }

    public function updateProfile(string $email, array $input): array
    {
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

        if ($firstName === '' || $lastName === '' || $role === '' || $department === '') {
            return ['ok' => false, 'message' => 'admin.flash.user_profile_invalid'];
        }

        $startedOn = $this->cleanDate((string) ($input['started_on'] ?? ''));
        $birthDate = $this->cleanDate((string) ($input['birth_date'] ?? ''));
        $leaveOpeningSnapshotDate = $this->cleanDate((string) ($input['leave_opening_snapshot_date'] ?? ''));
        $graduationYear = $this->cleanYear((string) ($input['graduation_year'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

        if ($password !== '') {
            if (strlen($password) < 6 || $password !== $passwordConfirmation) {
                return ['ok' => false, 'message' => 'admin.flash.password_invalid'];
            }

            $profile['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $profile = array_merge($profile, [
            'email' => $newEmail,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim($firstName . ' ' . $lastName),
            'role' => $role,
            'department' => $department,
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
            'updated_at' => date('Y-m-d H:i'),
        ]);

        $data = $this->loadWritableData();
        $data['profiles'] = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $newProfileKey = $this->profileKeyForEmail($newEmail, $profile, $data['profiles'], $email);

        unset($data['profiles'][$email]);
        $data['profiles'][$newProfileKey] = $this->profileForStorage($profile);
        $this->saveData($data);

        $currentUser = Session::get('user');

        if (is_array($currentUser) && ($currentUser['email'] ?? '') === $email) {
            $currentUser = array_merge($currentUser, $this->sessionProfile($profile));
            $currentUser['email'] = $newEmail !== '' ? $newEmail : $email;
            Session::put('user', $currentUser);
        }

        return [
            'ok' => true,
            'message' => 'admin.flash.user_profile_saved',
            'profile_key' => $newProfileKey,
            'old_email' => $currentEmail,
            'new_email' => $newEmail,
        ];
    }

    public function deleteProfile(string $email): array
    {
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

    public function exportProfilesCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');

        foreach ($this->users() as $profile) {
            $row = [];

            foreach (self::CSV_COLUMNS as $column) {
                $row[] = $column === 'password' ? '' : (string) ($profile[$column] ?? '');
            }

            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . $csv;
    }

    public function exportProfilesXlsx(): string
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
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheet());
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    public function importProfilesCsv(string $path): array
    {
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

            if ($firstName === '' || $lastName === '' || $role === '' || $department === '') {
                $skipped++;
                continue;
            }

            $profile = array_merge($profile, [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim($firstName . ' ' . $lastName),
                'role' => $role,
                'department' => $department,
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
                'updated_at' => date('Y-m-d H:i'),
            ]);

            $password = (string) ($record['password'] ?? '');

            if ($password !== '') {
                if (strlen($password) < 6) {
                    $skipped++;
                    continue;
                }

                $profile['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
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
        $data = $this->loadWritableData();
        $dirty = false;

        if (($data['version'] ?? null) !== self::VERSION || !isset($data['profiles']) || !is_array($data['profiles'])) {
            $data = ['version' => self::VERSION, 'profiles' => []];
            $dirty = true;
        }

        foreach ($this->baseUsers as $email => $baseUser) {
            $existing = is_array($data['profiles'][$email] ?? null) ? $data['profiles'][$email] : [];
            $profile = $this->mergeProfile($email, $baseUser, $existing);

            if (($data['profiles'][$email] ?? null) !== $this->profileForStorage($profile)) {
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

            if (($data['profiles'][$email] ?? null) !== $this->profileForStorage($profile)) {
                $data['profiles'][$email] = $this->profileForStorage($profile);
                $dirty = true;
            }
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

        return ['version' => self::VERSION, 'profiles' => $profiles];
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
            'email' => $email,
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'name' => (string) ($baseUser['name'] ?? $email),
            'role' => (string) ($baseUser['role'] ?? ''),
            'department' => (string) ($baseUser['department'] ?? ''),
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
            'password_hash' => '',
            'created_at' => date('Y-m-d H:i'),
            'updated_at' => date('Y-m-d H:i'),
        ], $stored);

        $profile['profile_key'] = $email;
        $profile['email'] = $emailValue;
        $profile['permissions'] = $baseUser['permissions'] ?? [];
        $profile['password'] = (string) ($baseUser['password'] ?? '');

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
            'permissions' => $this->normalizeEmail((string) ($stored['email'] ?? '')) === '' ? [] : self::DEFAULT_IMPORTED_PERMISSIONS,
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
            'name' => (string) ($profile['name'] ?? ''),
            'role' => (string) ($profile['role'] ?? ''),
            'department' => (string) ($profile['department'] ?? ''),
            'started_on' => (string) ($profile['started_on'] ?? ''),
            'birth_date' => (string) ($profile['birth_date'] ?? ''),
            'leave_opening_total_days' => (float) ($profile['leave_opening_total_days'] ?? 0),
            'leave_opening_used_days' => (float) ($profile['leave_opening_used_days'] ?? 0),
            'leave_opening_remaining_days' => (float) ($profile['leave_opening_remaining_days'] ?? 0),
            'leave_opening_snapshot_date' => (string) ($profile['leave_opening_snapshot_date'] ?? ''),
            'leave_opening_source' => (string) ($profile['leave_opening_source'] ?? ''),
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

    private function xlsxSheet(): string
    {
        $rows = [self::CSV_COLUMNS];

        foreach ($this->users() as $profile) {
            $row = [];

            foreach (self::CSV_COLUMNS as $column) {
                $row[] = $column === 'password' ? '' : (string) ($profile[$column] ?? '');
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
        $path = $this->dataPath();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saveData(array $data): void
    {
        $path = $this->dataPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
}
