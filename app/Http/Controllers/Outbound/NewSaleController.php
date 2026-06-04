<?php

namespace App\Http\Controllers\Outbound;

use App\Exports\Outbound\NewBulkySaleExport;
use App\Exports\Outbound\NewSaleExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class NewSaleController extends Controller
{
    public function exportSales(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            // validasi input
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $start = $request->start_date ?? 'all';
            $end = $request->end_date ?? 'all';

            $fileName = "sales-{$start}_to_{$end}-" . now()->format('YmdHis') . ".xlsx";
            $publicPath = 'exports';

            Excel::store(
                new NewSaleExport($request),
                $publicPath . '/' . $fileName,
                'public'
            );

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(
                true,
                'File berhasil diunduh',
                $downloadUrl
            );
        } catch (\Exception $e) {
            return new ResponseResource(
                false,
                'Gagal export: ' . $e->getMessage(),
                []
            );
        }
    }

    public function exportBulkySale(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $start = $request->start_date ?? 'all';
            $end = $request->end_date ?? 'all';

            $fileName = "bulky-sale-{$start}_to_{$end}.xlsx";
            $publicPath = 'exports';

            Excel::store(
                new NewBulkySaleExport($request),
                $publicPath . '/' . $fileName,
                'public'
            );

            return new ResponseResource(
                true,
                'File berhasil diunduh',
                asset('storage/' . $publicPath . '/' . $fileName)
            );
        } catch (\Exception $e) {
            return new ResponseResource(
                false,
                'Gagal export: ' . $e->getMessage(),
                []
            );
        }
    }
}
