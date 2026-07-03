<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaletBrand extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function productBrand()
    {
        return $this->belongsTo(ProductBrand::class, 'brand_id');
    }
}
