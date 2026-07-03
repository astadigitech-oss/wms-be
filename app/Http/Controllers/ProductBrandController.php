<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\ProductBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductBrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $productBrands = ProductBrand::paginate(20);
        $resource = new ResponseResource(true, "list brand", $productBrands);

        return $resource->response();
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
            'brand_name' => 'required'
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $request['brand_slug'] = Str::slug($request['brand_name']);
            $productBrand = ProductBrand::create($request->all());
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $productBrand);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductBrand $productBrand)
    {
        $resource = new ResponseResource(true, "Data brand produk", $productBrand);
        return $resource->response();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductBrand $productBrand)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductBrand $productBrand)
    {
        $validator = Validator::make($request->all(), [
            'brand_name' => 'required|min:3'
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $request['brand_slug'] = Str::slug($request['brand_name']);
            $productBrand->update($request->all());
            $resource = new ResponseResource(true, "Data berhasil di simpan!", $productBrand);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductBrand $productBrand)
    {
        try {
            $productBrand->delete();
            $resource = new ResponseResource(true, "Data berhasil di hapus!", $productBrand);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di hapus!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }
}
