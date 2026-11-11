<?php

namespace App\Services;

use App\Models\Receipt;

interface ReceiptExtractor
{
    public function extract(Receipt $receipt): array;
}
