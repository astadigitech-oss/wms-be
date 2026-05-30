<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySaldoSnapshot extends Model
{
    protected $table = 'daily_saldo_snapshots';

    protected $guarded = ['id'];

    protected $casts = [
        'breakdown'     => 'array',
        'snapshot_date' => 'date:Y-m-d',
    ];
}
