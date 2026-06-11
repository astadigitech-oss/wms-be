<?php

namespace App\Http\Controllers\Outbound;

use App\Exports\Outbound\NewCargoExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExportOutboundController extends Controller
{
    public function exportB2BBaru()
    {
        $filename = sprintf(
            'B2B_%s_%s.xlsx',
            now()->format('dmY'),
            now()->format('His')
        );

        $path = 'exports/' . $filename;

        Excel::store(
            new NewCargoExport(),
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
    }
}
