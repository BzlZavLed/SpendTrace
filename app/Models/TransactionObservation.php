<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionObservation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $casts = ['payload' => 'array', 'observed_at' => 'immutable_datetime'];
}
