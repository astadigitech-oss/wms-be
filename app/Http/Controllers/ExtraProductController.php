<?php

namespace App\Http\Controllers;

use App\Exports\ExportExtraProduct;
use App\Exports\ExportExtraRangeAll;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExtraProductController extends Controller
{
    public function export()
    {
        return Excel::download(
            new ExportExtraProduct(),
            'extra-product.xlsx'
        );
    }
    public function exportRangeAll(Request $request)
    {
        $request->validate(['start' => ['sometimes', 'required', 'date_format:Y-m-d'], 'end' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start',],]);
        $start = $request->query('start', '2026-01-01');
        $end = $request->query('end', now()->toDateString());
        return Excel::download(new ExportExtraRangeAll($start, $end), 'extra-range-all.xlsx');
    }
}
