<?php

/*
|--------------------------------------------------------------------------
| Billing (Stripe subscriptions + Számlázz.hu invoices)
|--------------------------------------------------------------------------
|
| Stripe Price IDs per plan and interval live in config/plans.php. The amounts
| configured there are the gross (VAT included) prices charged by Stripe; the
| invoice splits them into net + VAT according to vat_key below.
|
*/

return [
    'intervals' => ['monthly', 'yearly'],

    /* Requires a complete billing address before checkout (Hungarian invoice requirement). */
    'require_billing_details' => true,

    'vat' => [
        /* Számlázz.hu "afakulcs": a percentage such as 27, or AAM (alanyi adómentes), TAM, etc. */
        'key' => env('BILLING_VAT_KEY', '27'),
        'prices_include_vat' => (bool) env('BILLING_PRICES_INCLUDE_VAT', true),
    ],

    'szamlazz' => [
        'enabled' => (bool) env('SZAMLAZZ_ENABLED', false),
        'endpoint' => env('SZAMLAZZ_ENDPOINT', 'https://www.szamlazz.hu/szamla/'),
        'agent_key' => env('SZAMLAZZ_AGENT_KEY'),
        'e_invoice' => (bool) env('SZAMLAZZ_E_INVOICE', true),
        'send_email' => (bool) env('SZAMLAZZ_SEND_EMAIL', true),
        'prefix' => env('SZAMLAZZ_PREFIX'),
        'payment_method' => env('SZAMLAZZ_PAYMENT_METHOD', 'Bankkártya'),
        'reply_to' => env('SZAMLAZZ_REPLY_TO'),
        'timeout' => 30,
    ],

    'trial_reminder_days' => 3,
];
