<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletAlias extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['identifier' => 'array', 'name_confirmed_at' => 'datetime'];

    public static function fingerprint(array $identifier): string
    {
        return hash('sha256', json_encode([
            'label' => mb_strtolower(preg_replace('/\s+/u', ' ', trim($identifier['label']))),
            'last_four' => $identifier['last_four'] ?? null,
            'network' => isset($identifier['network']) ? mb_strtolower(trim($identifier['network'])) : null,
        ], JSON_THROW_ON_ERROR));
    }
}
