<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Palet;
use App\Models\PaletBrand;
use App\Models\ProductBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaletBrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $paletBrand = PaletBrand::all();
        $resource = new ResponseResource(true, "list brand palet", $paletBrand);

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
            'palet_id' => 'required|integer|exists:palets,id',
            'brands' => 'required|array',
            'brands.*' => 'required|integer|gt:0',
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $paletId = $request->input('palet_id');
            $brands = $request->input('brands');

            $createdBrands = [];
            foreach ($brands as $brandId) {
                $paletBrandName = ProductBrand::findOrFail($brandId)->brand_name;
                $paletBrand = PaletBrand::create([
                    'palet_id' => $paletId,
                    'brand_id' => $brandId,
                    'palet_brand_name' => $paletBrandName,
                ]);
                $createdBrands[] = $paletBrand;
            }
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $createdBrands);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(PaletBrand $paletBrand)
    {
        try {
            // Kirimkan data dalam response
            $resource = new ResponseResource(true, "Data brand palet", $paletBrand);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data tidak ditemukan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(404);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PaletBrand $paletBrand)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $paletId)
    {
        $palet = Palet::find($paletId);
        if (!$palet) {
            $resource = new ResponseResource(false, "Palet ID tidak ditemukan!", []);
            return $resource->response()->setStatusCode(422);
        }

        $validator = Validator::make($request->all(), [
            'brands' => 'required|array',
            'brands.*' => 'required|integer|gt:0',
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $brands = $request->input('brands');
            $updatedBrands = [];

            foreach ($brands as $brandId) {
                $paletBrandName = ProductBrand::findOrFail($brandId)->brand_name;

                // Gunakan updateOrCreate untuk memperbarui atau membuat data baru jika belum ada
                $paletBrand = PaletBrand::updateOrCreate(
                    ['palet_id' => $paletId, 'brand_id' => $brandId],
                    ['palet_brand_name' => $paletBrandName]
                );

                $updatedBrands[] = $paletBrand;
            }

            $resource = new ResponseResource(true, "Data berhasil diperbarui!", $updatedBrands);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal diperbarui!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PaletBrand $paletBrand)
    {
        try {
            $paletBrand->delete();

            $resource = new ResponseResource(true, "Data berhasil dihapus!", $paletBrand);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal dihapus!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }
}
