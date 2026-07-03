<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    public static function boot()
    {
        parent::boot();

        static::updated(function ($category) {
            New_product::where('new_category_product', $category->getOriginal('name_category'))
                ->update(['new_category_product' => $category->name_category]);
        });
    }

    public function palets()
    {
        return $this->hasMany(Palet::class, 'category_id');
    }
}
