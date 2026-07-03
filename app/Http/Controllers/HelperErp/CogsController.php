<?php

namespace App\Http\Controllers\HelperErp;

use App\Http\Controllers\Controller;
use App\Imports\CogsImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CogsController extends Controller
{
    public function importSupplierDanChannel(Request $request)
    {
        // 1. Validasi pastikan file-nya ada dan formatnya bener (xlsx/xls/csv)
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            // 2. Eksekusi import data
            Excel::import(new CogsImport, $request->file('file'));

            return response()->json([
                'success' => true,
                'message' => 'Mantap! Data supplier dan channel berhasil di-import.'
            ], 200);
        } catch (\Exception $e) {
            // 3. Handle kalau ada error pas proses import
            return response()->json([
                'success' => false,
                'message' => 'Waduh gagal import: ' . $e->getMessage()
            ], 500);
        }
    }
}
