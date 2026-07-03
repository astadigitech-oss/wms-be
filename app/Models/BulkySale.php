<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BulkySale extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function bulkyDocument()
    {
        return $this->belongsTo(BulkyDocument::class);
    }
    public function bagProduct()
    {
        return $this->belongsTo(BagProducts::class);
    }
}
