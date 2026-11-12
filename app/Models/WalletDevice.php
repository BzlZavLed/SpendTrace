<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletDevice extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['token_hash'];

    protected $casts = ['revoked_at' => 'immutable_datetime', 'last_test_at' => 'immutable_datetime', 'last_event_at' => 'immutable_datetime'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WalletConnection::class, 'wallet_connection_id');
    }
}
