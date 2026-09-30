<?php

namespace App\Http\Controllers;

use App\Exports\ExportExtraProduct;
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
}
