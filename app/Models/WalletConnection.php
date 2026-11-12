<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WalletConnection extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['token_hash'];

    protected $casts = ['card_mappings' => 'array', 'enabled' => 'boolean', 'last_event_at' => 'immutable_datetime', 'last_test_at' => 'immutable_datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(WalletDevice::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(WalletAlias::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WalletEvent::class);
    }
}
