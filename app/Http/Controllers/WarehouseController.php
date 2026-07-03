<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    /**
     * Display a listing of the resource with optional search.
     */
    public function index(Request $request)
    {
        try {
            $query = $request->input('q');
            $warehouses = Warehouse::latest();

            // Search functionality
            if ($query) {
                $warehouses = $warehouses->where('nama', 'LIKE', '%' . $query . '%')
                    ->orWhere('alamat', 'LIKE', '%' . $query . '%')
                    ->orWhere('provinsi', 'LIKE', '%' . $query . '%')
                    ->orWhere('kota', 'LIKE', '%' . $query . '%')
                    ->orWhere('kabupaten', 'LIKE', '%' . $query . '%')
                    ->orWhere('kecamatan', 'LIKE', '%' . $query . '%');
            }

            $warehouses = $warehouses->paginate(33);

            return new ResponseResource(true, "List of warehouses", $warehouses);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Error fetching warehouses: " . $e->getMessage(), []);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validate request data
            $request->validate([
                'nama' => 'required|string',
                'alamat' => 'required|string',
                'provinsi' => 'required|string',
                'kota' => 'required|string',
                'kabupaten' => 'required|string',
                'kecamatan' => 'required|string',
                'no_hp' => 'required|string|max:20',
                'latitude' => 'required|string',
                'longitude' => 'required|string',
            ]);

            // Create warehouse
            $warehouse = Warehouse::create($request->all());

            DB::commit();
            return new ResponseResource(true, "Warehouse created successfully", $warehouse);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error creating warehouse: " . $e->getMessage(), []);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Warehouse $warehouse)
    {
        try {
            if (!$warehouse) {
                return new ResponseResource(false, "Warehouse not found", []);
            }

            return new ResponseResource(true, "Warehouse details", $warehouse);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Error fetching warehouse: " . $e->getMessage(), []);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Warehouse $warehouse)
    {
        DB::beginTransaction();

        try {
            if (!$warehouse) {
                return new ResponseResource(false, "Warehouse not found", []);
            }

            // Validate request data
            $request->validate([
                'nama' => 'sometimes|required|string|max:255',
                'alamat' => 'sometimes|required|string|max:255',
                'provinsi' => 'sometimes|required|string|max:255',
                'kota' => 'sometimes|required|string|max:255',
                'kabupaten' => 'sometimes|required|string|max:255',
                'kecamatan' => 'sometimes|required|string|max:255',
                'no_hp' => 'sometimes|required|string|max:20',
                'latitude' => 'sometimes|required|string',
                'longitude' => 'sometimes|required|string',
            ]);

            // Update warehouse
            $warehouse->update($request->all());

            DB::commit();
            return new ResponseResource(true, "Warehouse updated successfully", $warehouse);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error updating warehouse: " . $e->getMessage(), []);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Warehouse $warehouse)
    {
        DB::beginTransaction();

        try {
            if (!$warehouse) {
                return new ResponseResource(false, "Warehouse not found", []);
            }

            // Delete warehouse
            $warehouse->delete();

            DB::commit();
            return new ResponseResource(true, "Warehouse deleted successfully", $warehouse);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error deleting warehouse: " . $e->getMessage(), []);
        }
    }
}
