<?php

$adminEmail = strtolower(trim((string) (getenv('APP_ADMIN_EMAIL') ?: 'admin@example.test')));
$adminPassword = (string) (getenv('APP_ADMIN_PASSWORD') ?: '');
$hrEmail = strtolower(trim((string) (getenv('APP_HR_EMAIL') ?: 'hr@example.test')));
$hrPassword = (string) (getenv('APP_HR_PASSWORD') ?: '');

return [
    'name' => getenv('APP_NAME') ?: 'MyTakii Intranet',
    'locale' => getenv('APP_LOCALE') ?: 'tr-TR',
    'fallback_locale' => 'en-US',
    'available_locales' => [
        'tr-TR' => 'Türkçe',
        'en-US' => 'English',
        'de-DE' => 'Deutsch',
        'ja-JP' => '日本語',
    ],
    'demo_users' => [
        $adminEmail => [
            'password' => $adminPassword,
            'name' => trim((string) (getenv('APP_ADMIN_NAME') ?: 'Company Administrator')),
            'role' => trim((string) (getenv('APP_ADMIN_ROLE') ?: 'System Administrator')),
            'department' => trim((string) (getenv('APP_ADMIN_DEPARTMENT') ?: 'Administration')),
            'started_on' => trim((string) (getenv('APP_ADMIN_STARTED_ON') ?: '')),
            'permissions' => ['*'],
        ],
        $hrEmail => [
            'password' => $hrPassword,
            'name' => trim((string) (getenv('APP_HR_NAME') ?: 'Human Resources')),
            'role' => trim((string) (getenv('APP_HR_ROLE') ?: 'HR Manager')),
            'department' => trim((string) (getenv('APP_HR_DEPARTMENT') ?: 'HR')),
            'started_on' => trim((string) (getenv('APP_HR_STARTED_ON') ?: '')),
            'workforce_roles' => ['hr'],
            'permissions' => [
                'module.announcements.access',
                'module.leave.access',
                'module.messages.access',
                'module.personnel.access',
                'module.shift.access',
                'module.documents.access',
                'messaging.send',
                'personnel.read',
                'personnel.write',
                'personnel.delete',
                'personnel.export',
                'shift.manage',
                'leave.request.create',
                'leave.request.manage.hr',
                'documents.view',
                'content.announcement.view',
            ],
        ],
    ],
];
