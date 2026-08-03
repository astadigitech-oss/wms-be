<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Ppn;
use Illuminate\Http\Request;

class PublicTaxController extends Controller
{
    /**
     * List tax (PPN) tanpa auth, hanya data penting.
     */
    public function index(Request $request)
    {
        $taxes = Ppn::query()
            ->select('id', 'ppn')
            ->get();

        return new ResponseResource(true, "Data Tax", $taxes);
    }
}