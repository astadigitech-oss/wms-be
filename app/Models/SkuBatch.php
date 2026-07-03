<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SkuBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'sku_product_old_id',
        'actual_quantity_batch',
        'damaged_quantity_batch',
        'type',
        'note',
        'created_by',
    ];

    public function skuProductOld()
    {
        return $this->belongsTo(SkuProductOld::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
