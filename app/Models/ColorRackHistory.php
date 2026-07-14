<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ColorRackHistory extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function colorRack()
    {
        return $this->belongsTo(ColorRack::class, 'color_rack_id');
    }

    public function newProduct()
    {
        return $this->belongsTo(New_product::class, 'new_product_id');
    }

    public function bundle()
    {
        return $this->belongsTo(Bundle::class, 'bundle_id');
    }
}
