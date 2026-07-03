<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FilterQcd;
use App\Models\New_product;
use App\Models\Color_tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\ProductQcd;

class FilterQcdController extends Controller
{
    /** 
     * Display a listing of the resource.
     */
    public function index()
    {
        $product_filters = FilterQcd::latest()->paginate(100);

        $totalNewPriceWithCategory = FilterQcd::whereNotNull('new_category_product')->sum('new_price_product');
        $totalOldPriceWithoutCategory = FilterQcd::whereNull('new_category_product')->sum('old_price_product');

        $totalNewPrice = $totalNewPriceWithCategory + $totalOldPriceWithoutCategory;

        $category = null;

        if ($totalNewPrice > 99999) {
            $category = Category::all();
        } else {
            foreach ($product_filters as $product_filter) {
                $product_filter->new_tag_product = Color_tag::where('min_price_color', '<=', $totalNewPrice)
                    ->where('max_price_color', '>=', $totalNewPrice)
                    ->select('fixed_price_color', 'name_color')->get();

            }
        }

        return new ResponseResource(true, "list product filter", [
            'total_new_price' => $totalNewPrice,
            'category' => $category,
            'data' => $product_filters
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store($id)
    {
        DB::beginTransaction();
        try {
            $product = New_product::findOrFail($id);
            $productFilter = FilterQcd::create($product->toArray());
            $product->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menambah list product qcd", $productFilter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(FilterQcd $filterQcd)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FilterQcd $filterQcd)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FilterQcd $filterQcd)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $product_filter = FilterQcd::findOrFail($id);
            New_product::create($product_filter->toArray());
            $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menghapus list product qcd", $product_filter);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    
}
