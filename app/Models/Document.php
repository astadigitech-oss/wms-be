<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Document extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function user_scan_webs()
    {
        return $this->hasMany(UserScanWeb::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(
            Channel::class,
            'cogs_reference', 
            'document_id',    
            'cogs_id'         
        )->withPivot('created_at');
    }
    
    public function cogs(): BelongsToMany
    {
        return $this->channels();
    }
}
