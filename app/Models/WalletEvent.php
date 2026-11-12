<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletEvent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['payload_hash', 'match_snapshot'];

    protected $casts = ['payload' => 'array', 'is_test' => 'boolean', 'match_snapshot' => 'array', 'dismissed_ids' => 'array'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WalletConnection::class, 'wallet_connection_id')->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
