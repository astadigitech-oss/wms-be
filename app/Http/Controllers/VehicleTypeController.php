<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $vehicleTypes = VehicleType::latest();
        if ($query) {
            $vehicleTypes = $vehicleTypes->where(function ($data) use ($query) {
                $data->where('vehicle_name', 'LIKE', '%' . $query . '%');
            });
        }
        $vehicleTypes = $vehicleTypes->paginate(10);
        $resource = new ResponseResource(true, "List kendaraan angkut", $vehicleTypes);
        return $resource->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_name' => 'required',
            'cargo_length' => 'nullable|numeric',
            'cargo_height' => 'nullable|numeric',
            'cargo_width' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        try {
            $vehicleType = VehicleType::create($request->all());

            $resource = new ResponseResource(true, "Data berhasil disimpan!", $vehicleType);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal disimpan!", $e->getMessage());
        }

        return $resource->response();
    }

    /**
     * Display the specified resource.
     */
    public function show(VehicleType $vehicleType)
    {
        $resource = new ResponseResource(true, "Data kendaraan angkut", $vehicleType);
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, VehicleType $vehicleType)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_name' => 'required',
            'cargo_length' => 'nullable|numeric',
            'cargo_height' => 'nullable|numeric',
            'cargo_width' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        try {
            $vehicleType->update($request->all());

            $resource = new ResponseResource(true, "Data berhasil disimpan!", $vehicleType);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal disimpan!", $e->getMessage());
        }

        return $resource->response();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VehicleType $vehicleType)
    {
        try {
            $vehicleType->delete();

            $resource = new ResponseResource(true, "Data berhasil dihapus!", $vehicleType);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal dihapus!", $e->getMessage());
        }

        return $resource->response();
    }
}
