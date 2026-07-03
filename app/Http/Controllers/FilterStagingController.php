<?php

namespace App\Http\Controllers;

use App\Models\RepairFilter;
use Illuminate\Http\Request;
use App\Models\FilterStaging;
use App\Models\New_product;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\Auth;

class FilterStagingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userId = auth()->id();
        $product_filtersByuser = StagingProduct::
        select(
            'id',
            'new_category_product',
            'new_price_product',
            'old_price_product',
            'new_tag_product',
            'stage'
        )->where('user_id', $userId)->where('stage', 'process')->get();

        $totalNewPriceWithCategory = $product_filtersByuser->whereNotNull('new_category_product')->sum('new_price_product');
        $totalOldPriceWithoutCategory = $product_filtersByuser->whereNull('new_category_product')->sum('old_price_product');
        $totalNewPriceWithoutCtgrTagColor = $product_filtersByuser
            ->whereNull('new_category_product')->whereNull('new_tag_product')->whereNull('old_price_product')->sum('new_price_product');
        $totalOldPriceWithoutCtgrTagColor = $product_filtersByuser->whereNull('new_category_product')
            ->whereNull('new_tag_product')->whereNull('new_price_product')->sum('old_price_product');


        $totalNewPrice = $totalNewPriceWithCategory + $totalOldPriceWithoutCategory + $totalNewPriceWithoutCtgrTagColor + $totalOldPriceWithoutCtgrTagColor;
        
        $product_filters = StagingProduct::select(
            'id',
            'new_barcode_product',
            'new_name_product',
            'new_category_product',
            'new_price_product',
            'old_price_product',
            'new_status_product',
            'stage'
            )
            ->where('user_id', $userId)->where('stage', 'process')->paginate(50);
        return new ResponseResource(true, "list product filter", [
            'total_new_price' => $totalNewPrice,
            'data' => $product_filters,
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
        $userId = auth()->id();
        try {
            $product = StagingProduct::findOrFail($id);
            $product->user_id = $userId;

            $duplicate = New_product::where('new_barcode_product', $product->new_barcode_product)->exists();
            if ($duplicate) {
                return new ResponseResource(false, "barcode product di inventory sudah ada : " . $product->new_barcode_product, null);
            }

            $product->update([
                'stage' => 'process',
                'user_id' => $userId
            ]);
            // $productFilter = FilterStaging::create($product->toArray());
            // $product->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menambah list product staging", $product);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(FilterStaging $filterStaging)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FilterStaging $filterStaging)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FilterStaging $filterStaging)
    {
        //
    } 

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            $product_filter = StagingProduct::findOrFail($id);
            $product_filter->update([
                'user_id' => $userId,
                'stage' => null
            ]);
            // StagingProduct::create($product_filter->toArray());
            // $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menghapus list product filter", $product_filter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

   
}
