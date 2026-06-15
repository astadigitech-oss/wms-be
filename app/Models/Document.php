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

    public function channels()
    {
        return $this->belongsToMany(
            CogsChannel::class,
            'cogs_reference',
            'document_id',
            'channel_id'
        )->withPivot('user_id', 'created_at');
    }

    // public function cogsReferences()
    // {
    //     return $this->hasMany(CogsReference::class, 'document_id', 'id');
    // }
}
