<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\ProductCondition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductConditionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $productConditions = ProductCondition::paginate(20);
        $resource = new ResponseResource(true, "list kondisi", $productConditions);

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
            'condition_name' => 'required|min:3'
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $request['condition_slug'] = Str::slug($request['condition_name']);
            $productCondition = ProductCondition::create($request->all());
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $productCondition);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductCondition $productCondition)
    {
        $resource = new ResponseResource(true, "Data kondisi produk", $productCondition);
        return $resource->response();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductCondition $productCondition)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductCondition $productCondition)
    {
        $validator = Validator::make($request->all(), [
            'condition_name' => 'required|min:3'
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $request['condition_slug'] = Str::slug($request['condition_name']);
            $productCondition->update($request->all());
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $productCondition);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductCondition $productCondition)
    {
        try {
            $productCondition->delete();
            $resource = new ResponseResource(true, "Data berhasil di hapus!", $productCondition);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di hapus!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }
}
