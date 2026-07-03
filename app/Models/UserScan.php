<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;


class UserScan extends Model
{
    use HasFactory;
    protected $guarded = ['id'];
    // $scanDate = Carbon::now('Asia/Jakarta')->toDateString();

    public static function updateOrCreateDailyScan($userId, $formatBarcodeId)
    {
        $scanDate = Carbon::now('Asia/Jakarta')->toDateString();

        // $scanDate = '2024-12-29';  

        $userScans = self::where('user_id', $userId)
            ->where('format_barcode_id', $formatBarcodeId)
            ->get();

        if ($userScans->isNotEmpty()) {
            $date = false;

            foreach ($userScans as $userScan) {
                if ($userScan->scan_date === $scanDate) {
                    $userScan->increment('total_scans');
                    $date = true;
                    break;
                } elseif ($userScan->scan_date === null) {

                    $userScan->update([
                        'total_scans' => $userScan->total_scans + 1,
                        'scan_date' => $scanDate,
                    ]);
                    $date = true;
                    break;
                }
            }

            if (!$date) {
                self::create([
                    'user_id' => $userId,
                    'format_barcode_id' => $formatBarcodeId,
                    'total_scans' => 1,
                    'scan_date' => $scanDate,
                ]);
            }
        } else {
            self::create([
                'user_id' => $userId,
                'format_barcode_id' => $formatBarcodeId,
                'total_scans' => 1,
                'scan_date' => $scanDate,
            ]);
        }
    }


    public function formatBarcode()
    {
        return $this->belongsTo(FormatBarcode::class, 'format_barcode_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
