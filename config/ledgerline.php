<?php

return [

    /*
    | Eager-load relations on the invoice list. Setting this to false reproduces the
    | v3.15.0 regression: one query per invoice for its customer and lines (N+1).
    */

    'eager_load' => env('LEDGERLINE_EAGER_LOAD', true),

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
