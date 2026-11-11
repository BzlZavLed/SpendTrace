<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['total_minor' => 'integer', 'corrected_fields' => 'array', 'review_reasons' => 'array', 'duplicate_ids' => 'array', 'excluded' => 'boolean', 'revision' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(TransactionObservation::class);
    }
}
