<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Repair extends Model
{
    use HasFactory;
    
    protected $guarded = ['id'];

    public function repair_products(){
        return $this->hasMany(RepairProduct::class);
    }
    public function notifications(){
        return $this->hasMany(Notification::class);
    }


}
