<?php

return [

    /*
    | Eager-load relations on the invoice list. Setting this to false reproduces the
    | v3.15.0 regression: one query per invoice for its customer and lines (N+1).
    */

    'eager_load' => env('LEDGERLINE_EAGER_LOAD', true),

    /*
    | Share of invoice emails (in percent) the simulated SMTP relay refuses, so the mail
    | queue sees retries and failures under live traffic.
    */

    'smtp_failure_percent' => (int) env('LEDGERLINE_SMTP_FAILURE_PERCENT', 3),

    /*
    | VAT rates by customer region. Legacy accounts imported from the old billing
    | system carry the region code "XI", which is missing here on purpose: rendering
    | their invoice PDFs throws, which is what the exceptions page shows.
    */

    'vat_rates' => [
        'UK' => 0.20,
        'IE' => 0.23,
        'DE' => 0.19,
        'FR' => 0.20,
        'NL' => 0.21,
        'US' => 0.00,
    ],

];
