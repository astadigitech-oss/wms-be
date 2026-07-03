<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class UserScanWeb extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    // Gabungkan appends untuk menghindari overwrite
    protected $appends = ['username', 'base_document', 'code_document'];

    public function getUsernameAttribute()
    {
        return $this->user ? $this->user->username : null; // Cek apakah user ada
    }

    public function getBaseDocumentAttribute()
    {
        return $this->document ? $this->document->base_document : null;
    }

    public function getCodeDocumentAttribute()
    {
        return $this->document ? $this->document->code_document : null;
    }


    public static function updateOrCreateDailyScan($userId, $documentId)
    {
        $scanDate = Carbon::now('Asia/Jakarta')->toDateString();
        // $scanDate = '2024-12-29';  

        $userScan = self::where('user_id', $userId)
            ->where('document_id', $documentId)
            ->where('scan_date', $scanDate)
            ->first();

        if ($userScan) {
            $userScan->increment('total_scans');
        } else {
            self::create([
                'user_id' => $userId,
                'document_id' => $documentId,
                'total_scans' => 1,
                'scan_date' => $scanDate,
            ]);
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
