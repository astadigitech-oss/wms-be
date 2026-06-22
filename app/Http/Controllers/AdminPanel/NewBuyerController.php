<?php

namespace App\Http\Controllers\AdminPanel;

use App\Exports\AdminPanel\BuyerExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;

class NewBuyerController extends Controller
{
    public function exportBuyer()
    {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '1024M');

        $filename = 'buyer-report-' .
            now()->format('Ymd_His') . '-' .
            Str::random(8) .
            '.xlsx';

        return Excel::download(
            new BuyerExport(),
            $filename
        );
    }
}
