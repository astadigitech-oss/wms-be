<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CogsSupplier extends Model
{
    use HasFactory;

    protected $table = 'cogs_supplier';
    
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'name'];

    public $timestamps = true;

    public function channels()
    {
        return $this->hasMany(CogsChannel::class, 'supplier_id', 'id');
    }
}