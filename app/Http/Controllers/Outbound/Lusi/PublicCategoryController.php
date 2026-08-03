<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Category;
use Illuminate\Http\Request;

class PublicCategoryController extends Controller
{
    /**
     * List category tanpa auth, hanya data penting.
     */
    public function index(Request $request)
    {
        $q = $request->query('q');

        $categories = Category::query()
            ->when($q, function ($query) use ($q) {
                $query->where('name_category', 'LIKE', '%' . $q . '%');
            })
            ->select('id', 'name_category', 'discount_category', 'max_price_category')
            ->get();

        return new ResponseResource(true, "Data Category", $categories);
    }
}