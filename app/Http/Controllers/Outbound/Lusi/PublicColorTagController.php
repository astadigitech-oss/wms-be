<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Color_tag;
use Illuminate\Http\Request;

class PublicColorTagController extends Controller
{
    /**
     * List tag warna tanpa auth, hanya data penting.
     */
    public function index(Request $request)
    {
        $q = $request->query('q');

        $colorTags = Color_tag::query()
            ->when($q, function ($query) use ($q) {
                $query->where('name_color', 'LIKE', '%' . $q . '%');
            })
            ->select('id', 'hexa_code_color', 'name_color', 'min_price_color', 'max_price_color', 'fixed_price_color')
            ->get();

        return new ResponseResource(true, "Data Tag Warna", $colorTags);
    }
}