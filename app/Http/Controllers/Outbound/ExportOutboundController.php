<?php

namespace App\Http\Controllers\Outbound;

use App\Exports\Outbound\NewCargoExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;

class ExportOutboundController extends Controller
{
    public function exportB2BBaru()
    {
        $filename = sprintf(
            'B2B_%s_%s_%s.xlsx',
            now()->format('dmY'),
            now()->format('His'),
            rand(1000, 9999)
        );

        return Excel::download(
            new NewCargoExport(),
            $filename
        );
    }
}
