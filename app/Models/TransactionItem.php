<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'quantity' => 'decimal:3'];
}
