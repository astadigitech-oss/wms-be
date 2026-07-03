<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BundleQcd extends Model
{
    use HasFactory;
    protected $guarded=['id'];
    public function product_qcds(){
        return $this->hasMany(ProductQcd::class);
    }
}
