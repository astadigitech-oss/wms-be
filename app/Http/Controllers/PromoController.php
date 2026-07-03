<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\New_product;
use App\Models\Promo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
    
        $promos = Promo::latest()
            ->join('new_products', 'new_products.id', '=', 'promos.new_product_id')
            ->where(function($queryBuilder) use ($query) {
                $queryBuilder->where('name_promo', 'LIKE', '%' . $query . '%')
                ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
            })
            ->select('promos.*')->with('new_product')
            ->paginate(100);
    
        return new ResponseResource(true, "list promo", $promos);
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
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name_promo' => 'required',
            'discount_promo' => 'required',
            'price_promo' => 'required'
        ]);


        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $new_product = New_product::where('id', $request->new_product_id)->first();

        if (!$new_product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $new_product->update([
            'new_status_product' => 'promo'
        ]);

        $promo = Promo::create([
            'new_product_id' => $request->new_product_id,
            'name_promo' => $request->name_promo,
            'discount_promo' => $request->discount_promo,
            'price_promo' => $request->price_promo
        ]);

        return new ResponseResource(true, "berhasil ditambah", $promo);
    }


    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $promo = Promo::with('new_product')->find($id);

        if (!$promo) {
            return new ResponseResource(false, "Promo not found", null);
        }

        return new ResponseResource(true, "Detail promo", $promo);
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Promo $promo)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Promo $promo)
    {
        $validator = Validator::make($request->all(), [
            'name_promo' => 'required',
            'discount_promo' => 'required',
            'price_promo' => 'required'
        ]);


        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // $new_product = New_product::where('id', $request->new_product_id)->first();

        // if (!$new_product) {
        //     return response()->json(['error' => 'Product not found'], 404);
        // }

        $promo->update($request->all());

        return new ResponseResource(true, "berhasil di edit", $promo);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy($promoId, $productId)
    {
        Promo::destroy($promoId);
        $new_product = New_product::where('id', $productId)->first();
        $new_product->update([
            'new_status_product' => 'expired'
        ]);

        return new ResponseResource(true, "berhasil di hapus", null);
    }
}
