<?php

return [
    [
        'slug' => 'announcements',
        'code' => 'NW',
        'title_key' => 'module.announcements.title',
        'summary_key' => 'module.announcements.summary',
        'permission' => 'module.announcements.access',
        'accent' => '#2f6f62',
        'metrics' => [
            ['label_key' => 'metric.active_posts', 'value' => '18'],
            ['label_key' => 'metric.read_rate', 'value' => '92%'],
        ],
    ],
    [
        'slug' => 'leave',
        'code' => 'LV',
        'title_key' => 'module.leave.title',
        'summary_key' => 'module.leave.summary',
        'permission' => 'module.leave.access',
        'accent' => '#4a68a8',
        'metrics' => [
            ['label_key' => 'metric.pending_approvals', 'value' => '7'],
            ['label_key' => 'metric.avg_cycle', 'value' => '1.6d'],
        ],
    ],
    [
        'slug' => 'messages',
        'code' => 'MS',
        'title_key' => 'module.messages.title',
        'summary_key' => 'module.messages.summary',
        'permission' => 'module.messages.access',
        'accent' => '#3f7f92',
        'metrics' => [
            ['label_key' => 'metric.unread_messages', 'value' => '3'],
            ['label_key' => 'metric.sent_today', 'value' => '8'],
        ],
    ],
    [
        'slug' => 'personnel',
        'code' => 'HR',
        'title_key' => 'module.personnel.title',
        'summary_key' => 'module.personnel.summary',
        'permission' => 'module.personnel.access',
        'accent' => '#2f6f62',
        'metrics' => [
            ['label_key' => 'metric.personnel_records', 'value' => '178'],
            ['label_key' => 'metric.personnel_scope', 'value' => 'R/W/D'],
        ],
    ],
    [
        'slug' => 'documents',
        'code' => 'DC',
        'title_key' => 'module.documents.title',
        'summary_key' => 'module.documents.summary',
        'permission' => 'module.documents.access',
        'accent' => '#7b5a3d',
        'metrics' => [
            ['label_key' => 'metric.files', 'value' => '246'],
            ['label_key' => 'metric.expiring', 'value' => '4'],
        ],
    ],
    [
        'slug' => 'budget',
        'code' => 'BG',
        'title_key' => 'module.budget.title',
        'summary_key' => 'module.budget.summary',
        'permission' => 'module.budget.access',
        'accent' => '#8c4f6f',
        'metrics' => [
            ['label_key' => 'metric.annual_budget', 'value' => '$1.2M'],
            ['label_key' => 'metric.used_budget', 'value' => '63%'],
        ],
    ],
    [
        'slug' => 'procurement',
        'code' => 'PR',
        'title_key' => 'module.procurement.title',
        'summary_key' => 'module.procurement.summary',
        'permission' => 'module.procurement.access',
        'accent' => '#b0643c',
        'metrics' => [
            ['label_key' => 'metric.open_requests', 'value' => '21'],
            ['label_key' => 'metric.waiting_finance', 'value' => '5'],
        ],
    ],
    [
        'slug' => 'templates',
        'code' => 'TP',
        'title_key' => 'module.templates.title',
        'summary_key' => 'module.templates.summary',
        'permission' => 'module.templates.access',
        'accent' => '#2f6f62',
        'metrics' => [
            ['label_key' => 'metric.mail_templates', 'value' => '1'],
            ['label_key' => 'metric.report_templates', 'value' => '1'],
        ],
    ],
    [
        'slug' => 'audit',
        'code' => 'AU',
        'title_key' => 'module.audit.title',
        'summary_key' => 'module.audit.summary',
        'permission' => 'module.audit.access',
        'accent' => '#5d6470',
        'metrics' => [
            ['label_key' => 'metric.events_today', 'value' => '134'],
            ['label_key' => 'metric.risk_flags', 'value' => '2'],
        ],
    ],
];
