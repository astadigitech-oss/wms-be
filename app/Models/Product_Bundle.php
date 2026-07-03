<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product_Bundle extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    protected $appends = ['days_since_created'];

    public function getDaysSinceCreatedAttribute()
    {
        return Carbon::parse($this->created_at)->diffInDays(Carbon::now()) . ' Hari';
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
