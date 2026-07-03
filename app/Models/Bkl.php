<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Bkl extends Model
{
    use HasFactory;
    protected $guarded = ['id'];
    protected $appends = ['days_since_created', 'days_since_updated'];


    public function getDaysSinceCreatedAttribute()
    {
        return Carbon::parse($this->created_at)->diffInDays(Carbon::now()) . ' Hari';
    }
    public function getDaysSinceUpdatedAttribute()
    {
        return Carbon::parse($this->updated_at)->diffInDays(Carbon::now()) . ' Hari';
    }
}
