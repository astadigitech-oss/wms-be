<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cogs extends Model
{
    use HasFactory;

    protected $table = 'cogs';

    protected $fillable = [
        'supplier',
        'channel',
        'type',
        'amount',
    ];

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            Document::class,
            'cogs_reference',
            'cogs_id',
            'document_id'
        );
    }
}
