<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletPairing extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['code_hash'];

    protected $casts = ['expires_at' => 'immutable_datetime', 'redeemed_at' => 'immutable_datetime'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WalletConnection::class, 'wallet_connection_id');
    }
}
