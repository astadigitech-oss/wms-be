<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaletImage extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected $appends = ['file_path'];

    public function getFilePathAttribute()
    {
        return Storage::url('product-images/' . $this->attributes['filename']);
    }
    public function palet()
    {
        return $this->belongsTo(Palet::class, 'palet_id');
    }
}
