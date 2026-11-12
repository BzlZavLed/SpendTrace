<?php

return [
    'wallet_shortcut_url' => env('WALLET_SHORTCUT_URL'),
    'wallet_shortcut_version' => env('WALLET_SHORTCUT_VERSION', '0.1.0-unreleased'),
    'wallet_shortcut_verified' => env('WALLET_SHORTCUT_VERIFIED', false),
    'wallet_public_url' => env('WALLET_PUBLIC_URL'),
    'extraction_version' => 'receipt-v3',
    'provider' => env('RECEIPT_PROVIDER', 'fake'),
    'openai_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    'timezone' => 'America/New_York',
    'categories' => ['Groceries', 'Dining', 'Transport', 'Shopping', 'Health', 'Home', 'Entertainment', 'Other'],
    'currencies' => ['USD' => 2, 'CAD' => 2, 'EUR' => 2, 'GBP' => 2, 'JPY' => 0, 'KWD' => 3],
    'institutions' => ['Bank of America', 'Capital One', 'Chime', 'Synchrony', 'Other'],
];
