<?php

namespace App\Imports;

use App\Models\CogsSupplier;
use App\Models\CogsChannel;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Str;

class CogsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        // Skip kalau baris kosong
        if (empty($row['supplier']) || empty($row['channel'])) {
            return null;
        }

        // 1. Cari atau buat Supplier
        $supplier = CogsSupplier::firstOrCreate(
            ['name' => trim($row['supplier'])],
            ['id' => (string) Str::uuid()]
        );

        // 2. Ambil nilai tarif, bersihkan dari spasi/persen, lalu cast ke float
        $tarifRaw = isset($row['tarif']) ? str_replace(['%', ' '], '', $row['tarif']) : 0;
        $amount = (float) $tarifRaw;

        // 3. Ambil type langsung dari kolom excel (default ke percentage kalau kosong)
        $type = !empty($row['type']) ? strtolower(trim($row['type'])) : 'percentage';

        // 4. Masuk ke Channel
        return new CogsChannel([
            'id' => (string) Str::uuid(),
            'name' => trim($row['channel']),
            'supplier_id' => $supplier->id,
            'type' => $type,
            'amount' => $amount,
        ]);
    }
}
