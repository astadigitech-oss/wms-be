<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class FormatBarcode extends Model
{
    use HasFactory;
    // use SoftDeletes;
    protected $guarded = ['id'];
    protected $appends = ['username'];
    protected $dates = ['deleted_at'];

    public function getUsernameAttribute()
    {
        return $this->users()->first()?->username ?? null; 
    }

    public function users()
    {
        return $this->hasMany(User::class, 'format_barcode_id');
    }

    public function user_scans(){
        return $this->hasMany(UserScan::class);
    }
}
