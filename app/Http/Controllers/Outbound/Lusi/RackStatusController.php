<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Rack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RackStatusController extends Controller
{
    public function updateStatusByExcel(Request $request)
    {
        set_time_limit(3600);
        ini_set('memory_limit', '1024M');

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);

            if (empty($rows)) {
                return response()->json(['status' => false, 'message' => 'File Excel kosong.'], 422);
            }

            $firstRow = array_map(function ($value) {
                return strtolower(trim((string) $value));
            }, $rows[0]);

            $barcodeColumnIndex = array_search('barcode', $firstRow, true);
            $startRow = 1;

            if ($barcodeColumnIndex === false) {
                $barcodeColumnIndex = 0;
                $startRow = 0;
            }

            $barcodes = [];
            for ($i = $startRow; $i < count($rows); $i++) {
                $barcode = trim((string) ($rows[$i][$barcodeColumnIndex] ?? ''));

                if ($barcode === '') {
                    continue;
                }

                $barcodes[$barcode] = $barcode;
            }

            if (empty($barcodes)) {
                return response()->json(['status' => false, 'message' => 'Tidak ada barcode yang valid di file Excel.'], 422);
            }

            $barcodeList = array_values($barcodes);
            $matchedCount = Rack::whereIn('barcode', $barcodeList)->count();

            Rack::whereIn('barcode', $barcodeList)->update([
                'status' => 'done',
            ]);

            return new ResponseResource(true, 'Berhasil mengubah status rack menjadi done', [
                'total_barcodes' => count($barcodeList),
                'matched_racks' => (int) $matchedCount,
                'missing_barcodes' => (int) (count($barcodeList) - $matchedCount),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
