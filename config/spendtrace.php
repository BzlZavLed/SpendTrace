<?php

return [
    'provider' => env('RECEIPT_PROVIDER', 'fake'),
    'openai_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    'timezone' => 'America/New_York',
    'categories' => ['Groceries', 'Dining', 'Transport', 'Shopping', 'Health', 'Home', 'Entertainment', 'Other'],
    'currencies' => ['USD' => 2, 'CAD' => 2, 'EUR' => 2, 'GBP' => 2, 'JPY' => 0, 'KWD' => 3],
    'institutions' => ['Bank of America', 'Capital One', 'Chime', 'Synchrony', 'Other'],
];
