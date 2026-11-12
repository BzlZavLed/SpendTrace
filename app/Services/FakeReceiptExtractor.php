<?php

namespace App\Services;

use App\Models\Receipt;

class FakeReceiptExtractor implements ReceiptExtractor
{
    public function extract(Receipt $receipt): array
    {
        return [
            'merchant' => null, 'purchase_date' => null, 'purchase_time' => null,
            'total_minor' => null, 'currency' => null, 'card_last_four' => null, 'category' => null,
            'printed_item_count' => null, 'items' => [], 'subtotal_minor' => null, 'tax_minor' => null, 'tip_minor' => null,
            'total_decimal' => null, 'subtotal_decimal' => null, 'tax_decimal' => null, 'tip_decimal' => null, 'discount_decimal' => null,
            'discount_minor' => null, 'arithmetic_complete' => false,
            'review_reasons' => ['Fake extraction: no image was read. Enter the real receipt details or switch to the real provider.'],
        ];
    }
}
