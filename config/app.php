<?php

$adminEmail = strtolower(trim((string) (getenv('APP_ADMIN_EMAIL') ?: 'bilal@bigabilisim.com')));
$adminPassword = (string) (getenv('APP_ADMIN_PASSWORD') ?: '');
$hrEmail = strtolower(trim((string) (getenv('APP_HR_EMAIL') ?: 'y.ekici@takii.com.tr')));
$hrPassword = (string) (getenv('APP_HR_PASSWORD') ?: '');

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
            'name' => 'Bilal Bozduman',
            'role' => 'System Admin',
            'department' => 'Operations',
            'started_on' => '2021-05-20',
            'permissions' => ['*'],
        ],
        $hrEmail => [
            'password' => $hrPassword,
            'name' => 'Yeşim Dingil Ekici',
            'role' => 'HR Specialist',
            'department' => 'IK',
            'started_on' => '2022-11-03',
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
