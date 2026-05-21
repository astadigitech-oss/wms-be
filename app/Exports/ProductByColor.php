<?php

namespace App\Exports;

use Illuminate\Http\Request;
use App\Models\New_product;
use App\Models\Bundle;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductByColor implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    use Exportable;

    protected $querySearch;

    public function __construct(Request $request)
    {
        $this->querySearch = $request->input('q');
    }

    public function query()
    {
        $np = (new New_product)->getTable();
        $bd = (new Bundle)->getTable();

        $productQuery = New_product::select(
            "$np.id",
            "$np.old_barcode_product",
            "$np.new_barcode_product",
            "$np.new_name_product",
            "$np.new_date_in_product",
            "$np.new_status_product",
            "$np.new_tag_product",
            "$np.new_price_product",
            DB::raw("'display' as source"),
            'color_racks.barcode'
        )
            ->leftJoin('color_rack_products', "$np.id", '=', 'color_rack_products.new_product_id')
            ->leftJoin('color_racks', 'color_rack_products.color_rack_id', '=', 'color_racks.id')
            ->whereNotNull("$np.new_tag_product")
            ->whereNull("$np.new_category_product")
            ->whereNull("$np.is_so")
            ->where("$np.is_pending", false)
            ->whereJsonContains("$np.new_quality->lolos", 'lolos')
            ->whereIn("$np.new_status_product", ['display', 'expired', 'slow_moving'])
            ->where(function ($q) use ($np) {
                $q->whereNull("$np.type")->orWhere("$np.type", 'type1');
            });

        $bundleQuery = Bundle::select(
            "$bd.id",
            "$bd.old_barcode_bundle as old_barcode_product",
            "$bd.barcode_bundle as new_barcode_product",
            "$bd.name_bundle as new_name_product",
            "$bd.created_at as new_date_in_product",
            DB::raw("CASE WHEN $bd.product_status = 'not sale' THEN 'display' ELSE $bd.product_status END as new_status_product"),
            "$bd.name_color as new_tag_product",
            "$bd.total_price_custom_bundle as new_price_product",
            DB::raw("'bundle' as source"),
            'color_racks.barcode'
        )
            ->leftJoin('color_rack_products', "$bd.id", '=', 'color_rack_products.bundle_id')
            ->leftJoin('color_racks', 'color_rack_products.color_rack_id', '=', 'color_racks.id')
            ->whereNotNull("$bd.name_color")
            ->whereNull("$bd.category")
            ->whereIn("$bd.product_status", ['not sale'])
            ->where(function ($q) use ($bd) {
                $q->whereNull("$bd.type")
                    ->orWhere("$bd.type", 'type1')
                    ->orWhere("$bd.type", 'type2');
            });

        // 3. Filter pencarian
        if ($this->querySearch) {
            $productQuery->where(function ($subQuery) use ($np) {
                $subQuery->where("$np.new_tag_product", 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere("$np.new_barcode_product", 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere("$np.old_barcode_product", 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere("$np.new_name_product", 'LIKE', '%' . $this->querySearch . '%');
            });

            $bundleQuery->where(function ($subQuery) use ($bd) {
                $subQuery->where("$bd.name_color", 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere("$bd.barcode_bundle", 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere("$bd.old_barcode_bundle", 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere("$bd.name_bundle", 'LIKE', '%' . $this->querySearch . '%');
            });
        }

        $unionQuery = $productQuery->unionAll($bundleQuery);
        
        return DB::query()->fromSub($unionQuery, 'combined_data')
            ->orderBy('new_date_in_product', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Old Barcode',
            'New Barcode',
            'Product Name',
            'Date In',
            'Status',
            'Tag / Color',
            'Price',
            'Source',
            'Barcode Rak'
        ];
    }

    private function cleanString($string)
    {
        if (is_null($string) || $string === '') {
            return '';
        }

        $string = (string) $string;
        $string = htmlspecialchars_decode(htmlspecialchars($string, ENT_IGNORE | ENT_SUBSTITUTE, 'UTF-8'));
        $string = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $string);

        return trim($string);
    }

    public function map($row): array
    {
        return [
            $this->cleanString($row->id),
            $this->cleanString($row->old_barcode_product),
            $this->cleanString($row->new_barcode_product),
            $this->cleanString($row->new_name_product),
            $this->cleanString($row->new_date_in_product),
            $this->cleanString($row->new_status_product),
            $this->cleanString($row->new_tag_product),
            $this->cleanString($row->new_price_product),
            $this->cleanString($row->source),
            $this->cleanString($row->barcode),
        ];
    }

    public function title(): string
    {
        return 'All Products';
    }
}