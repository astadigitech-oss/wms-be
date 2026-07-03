<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProductBrand extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted()
    {
        static::updated(function ($productBrand) {
            // Update palet_brand_name dengan menggunakan relasi Eloquent
            $paletBrands = PaletBrand::where('brand_id', $productBrand->id)->get();

            foreach ($paletBrands as $paletBrand) {
                $paletBrand->palet_brand_name = $productBrand->brand_name;
                $paletBrand->save();
            }
        });
    }
}
