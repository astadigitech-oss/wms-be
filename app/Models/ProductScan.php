<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductScan extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    // protected $appends = ['file_path'];

    // public function getFilePathAttribute()
    // {
    //     return Storage::url('product-images/' . $this->attributes['filename']);
    // }

    public function user(){
        return $this->belongsTo(User::class);
    }

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        return Storage::url('product_images/' . $this->image);
    }
}
