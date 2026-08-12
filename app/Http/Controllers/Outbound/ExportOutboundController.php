<?php

namespace App\Http\Controllers\Outbound;

use App\Exports\Outbound\NewCargoExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExportOutboundController extends Controller
{
    public function exportB2BBaru(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validasi opsional
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        // Jika ada input 'date', gunakan tanggal tersebut di akhir hari (23:59:59).
        // Jika tidak ada, default ke hari ini akhir hari.
        $exportDate = $request->input('date')
            ? Carbon::parse($request->input('date'))->endOfDay()
            : now()->endOfDay();

        try {
            // Penamaan file disesuaikan dengan tanggal export yang dipilih
            $filename = sprintf(
                'B2B_%s_%s.xlsx',
                $exportDate->format('dmY'),
                now()->format('His')
            );

            $path = 'exports/' . $filename;

            // Kirim $exportDate ke constructor NewCargoExport
            Excel::store(
                new NewCargoExport($exportDate),
                $path,
                'public'
            );

            return new ResponseResource(
                true,
                'Berhasil generate file B2B',
                [
                    'file_name' => $filename,
                    'link' => Storage::disk('public')->url($path),
                ]
            );
        } catch (\Throwable $th) {

            Log::error('Export B2B gagal', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString(),
            ]);

            return new ResponseResource(
                false,
                'Gagal generate file B2B',
                [
                    'error' => $th->getMessage(),
                ]
            );
        }
    }
}
