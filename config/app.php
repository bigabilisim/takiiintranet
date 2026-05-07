<?php

$adminEmail = strtolower(trim((string) (getenv('APP_ADMIN_EMAIL') ?: 'admin@example.com')));
$adminPassword = (string) (getenv('APP_ADMIN_PASSWORD') ?: 'admin123');
$hrEmail = strtolower(trim((string) (getenv('APP_HR_EMAIL') ?: 'hr@example.com')));
$hrPassword = (string) (getenv('APP_HR_PASSWORD') ?: 'hr123');

return [
    'name' => getenv('APP_NAME') ?: 'Kanso Intranet',
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
            'name' => 'Admin User',
            'role' => 'System Admin',
            'department' => 'Operations',
            'started_on' => '2021-05-20',
            'permissions' => ['*'],
        ],
        $hrEmail => [
            'password' => $hrPassword,
            'name' => 'HR User',
            'role' => 'HR Specialist',
            'department' => 'IK',
            'started_on' => '2022-11-03',
            'permissions' => [
                'module.announcements.access',
                'module.leave.access',
                'module.messages.access',
                'module.personnel.access',
                'module.documents.access',
                'messaging.send',
                'personnel.read',
                'personnel.write',
                'personnel.delete',
                'personnel.export',
                'leave.request.create',
                'leave.request.manage.hr',
                'documents.view',
                'content.announcement.view',
            ],
        ],
    ],
];
