<?php

namespace App\Http\Controllers\Urgent;

use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use App\Models\ColorRackProduct;
use App\Models\New_product;

class MbakNisaController extends Controller
{
    public function checkBarcode(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv',
        ]);

        $rows = Excel::toArray([], $request->file('file'));

        // Ambil barcode (skip header)
        $barcodes = collect($rows[0])
            ->skip(1)
            ->pluck(0)
            ->filter()
            ->unique()
            ->values();

        // Ambil product berdasarkan barcode
        $products = New_product::whereIn('new_barcode_product', $barcodes)->get();

        // Ambil semua product_id yang sudah ada di color_rack_products
        $existingIds = ColorRackProduct::whereIn('new_product_id', $products->pluck('id'))
            ->pluck('new_product_id');

        $result = $products->map(function ($product) use ($existingIds) {
            return [
                'barcode' => $product->new_barcode_product,
                'product_id' => $product->id,
                'status' => $existingIds->contains($product->id)
                    ? 'SUDAH ADA'
                    : 'BELUM ADA',
            ];
        });

        return response()->json($result);
    }
}
