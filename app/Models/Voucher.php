<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Voucher extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'code',
        'name',
        'amount',
        'max_usage',
        'is_active',
        'max_week',
    ];

    protected static function booted()
    {
        static::creating(function ($voucher) {
            if (empty($voucher->code)) {
                do {
                    $code = 'VCR-' . now()->format('my') . '-' . strtoupper(Str::random(6));
                } while (self::where('code', $code)->exists());

                $voucher->code = $code;
            }
        });
    }

    public function buyers()
    {
        return $this->belongsToMany(Buyer::class)
            ->withPivot([
                'start_date',
                'status'
            ])
            ->withTimestamps();
    }
}
