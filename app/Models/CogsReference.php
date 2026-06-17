<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CogsReference extends Model
{
    use HasFactory;

    protected $table = 'cogs_reference';

    public $incrementing = false;
    protected $primaryKey = ['channel_id', 'document_id'];

    const UPDATED_AT = null;

    protected $fillable = ['channel_id', 'document_id', 'user_id', 'type'];

    public function channel()
    {
        return $this->belongsTo(CogsChannel::class, 'channel_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
