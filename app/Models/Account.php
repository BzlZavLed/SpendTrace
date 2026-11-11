<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function identifiers(): HasMany
    {
        return $this->hasMany(AccountIdentifier::class);
    }
}
