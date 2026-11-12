<?php

return [
    /* Super Admins must have two-factor authentication enabled to use the platform console. */
    'require_two_factor' => (bool) env('ADMIN_REQUIRE_2FA', true),

    /* Addresses that receive new support tickets in addition to the Super Admins. */
    'support_emails' => array_filter(explode(',', (string) env('SUPPORT_NOTIFY_EMAILS', ''))),
];
