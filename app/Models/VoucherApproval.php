<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'requested_by',
        'approved_by',
        'voucher_id',
        'buyer_id',
        'nominal',
        'usage',
        'status',
        'date_request',
        'date_approved',
        'sale_document_id',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'date_request' => 'datetime',
        'date_approved' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function saleDocument()
    {
        return $this->belongsTo(SaleDocument::class);
    }
}
