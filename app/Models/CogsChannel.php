<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CogsChannel extends Model
{
    use HasFactory;

    protected $table = 'cogs_channel';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'name', 'supplier_id', 'type', 'amount'];

    public function supplier()
    {
        return $this->belongsTo(CogsSupplier::class, 'supplier_id', 'id');
    }

    public function references()
    {
        return $this->hasMany(CogsReference::class, 'channel_id', 'id');
    }
}