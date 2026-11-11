<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptImage extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['path', 'sha256'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
