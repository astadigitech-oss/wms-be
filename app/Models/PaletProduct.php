<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaletProduct extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    public function palet(){
        return $this->belongsTo(Palet::class);
    }
}
