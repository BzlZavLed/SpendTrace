<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Receipt extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['generation' => 'integer'];

    public function images(): HasMany
    {
        return $this->hasMany(ReceiptImage::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ExtractionRun::class);
    }
}
