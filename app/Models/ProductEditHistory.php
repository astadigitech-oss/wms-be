<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductEditHistory extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function requestUser()
    {
        return $this->belongsTo(User::class, 'request_user_id');
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function document()
    {
        return $this->belongsTo(\App\Models\Document::class, 'code_document', 'code_document');
    }
}