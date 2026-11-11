<?php

/*
|--------------------------------------------------------------------------
| Subscription plans
|--------------------------------------------------------------------------
|
| Prices and limits are starting business hypotheses (see product spec, section 4-5).
| A null limit means "unlimited" (subject to the fair-use caps below).
|
*/

return [
    'trial_days' => 14,
    'trial_plan' => 'pro',

    'fair_use' => [
        'clients' => 50000,
        'active_leads' => 50000,
        'active_tasks' => 20000,
        'import_max_rows' => 20000,
        'import_max_file_kb' => 10240,
    ],

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'currency' => 'HUF',
            'limits' => [
                'users' => 1,
                'clients' => 100,
                'active_leads' => 25,
                'forms' => 1,
                'submissions_per_month' => 50,
                'pipelines' => 1,
                'active_tasks' => 20,
                'automations' => 0,
                'custom_fields' => 0,
                'imports' => 1,
                'import_max_rows' => 50,
            ],
            'features' => [
                'advanced_search' => false,
                'import_update_duplicates' => false,
                'custom_form_fields' => false,
                'remove_branding' => false,
                'utm_tracking' => false,
                'advanced_reports' => false,
                'excel_export' => false,
                'email_reminders' => false,
                'auto_assignment' => false,
                'team' => false,
                'team_reports' => false,
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 2990,
            'currency' => 'HUF',
            'limits' => [
                'users' => 1,
                'clients' => null,
                'active_leads' => null,
                'forms' => 5,
                'submissions_per_month' => 1000,
                'pipelines' => 3,
                'active_tasks' => null,
                'automations' => 5,
                'custom_fields' => 10,
                'imports' => null,
                'import_max_rows' => null,
            ],
            'features' => [
                'advanced_search' => true,
                'import_update_duplicates' => true,
                'custom_form_fields' => true,
                'remove_branding' => true,
                'utm_tracking' => true,
                'advanced_reports' => true,
                'excel_export' => true,
                'email_reminders' => true,
                'auto_assignment' => false,
                'team' => false,
                'team_reports' => false,
            ],
        ],
        'business' => [
            'name' => 'Business',
            'price' => 5990,
            'currency' => 'HUF',
            'limits' => [
                'users' => 5,
                'clients' => null,
                'active_leads' => null,
                'forms' => 20,
                'submissions_per_month' => 5000,
                'pipelines' => 10,
                'active_tasks' => null,
                'automations' => 25,
                'custom_fields' => 30,
                'imports' => null,
                'import_max_rows' => null,
            ],
            'features' => [
                'advanced_search' => true,
                'import_update_duplicates' => true,
                'custom_form_fields' => true,
                'remove_branding' => true,
                'utm_tracking' => true,
                'advanced_reports' => true,
                'excel_export' => true,
                'email_reminders' => true,
                'auto_assignment' => true,
                'team' => true,
                'team_reports' => true,
            ],
        ],
    ],
];
