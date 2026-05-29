<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScanPending extends Model
{
    use HasFactory;
    protected $fillable = [
        'source_model',
        'source_id',
        'edited_name',
        'edited_qty',
        'status',
        'editor_id',
        'approver_id',
        'approved_at',
        'reason',
    ];


    public function editor()
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
