<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CogsChannel extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'supplier_id',
        'type',
        'amount',
    ];

    public function supplier()
    {
        return $this->belongsTo(CogsSupplier::class, 'supplier_id', 'id');
    }
}
