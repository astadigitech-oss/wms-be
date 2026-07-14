<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateProductRows = DB::table('color_rack_products')
            ->select('color_rack_id', 'new_product_id')
            ->whereNotNull('new_product_id')
            ->groupBy('color_rack_id', 'new_product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateProductRows as $row) {
            $duplicates = DB::table('color_rack_products')
                ->where('color_rack_id', $row->color_rack_id)
                ->where('new_product_id', $row->new_product_id)
                ->orderBy('id')
                ->get();

            foreach ($duplicates->slice(1) as $duplicate) {
                DB::table('color_rack_products')->where('id', $duplicate->id)->delete();
            }
        }

        $duplicateBundleRows = DB::table('color_rack_products')
            ->select('color_rack_id', 'bundle_id')
            ->whereNotNull('bundle_id')
            ->groupBy('color_rack_id', 'bundle_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateBundleRows as $row) {
            $duplicates = DB::table('color_rack_products')
                ->where('color_rack_id', $row->color_rack_id)
                ->where('bundle_id', $row->bundle_id)
                ->orderBy('id')
                ->get();

            foreach ($duplicates->slice(1) as $duplicate) {
                DB::table('color_rack_products')->where('id', $duplicate->id)->delete();
            }
        }

        Schema::table('color_rack_products', function (Blueprint $table) {
            $table->unique(['color_rack_id', 'new_product_id'], 'color_rack_products_rack_product_unique');
            $table->unique(['color_rack_id', 'bundle_id'], 'color_rack_products_rack_bundle_unique');
        });

        DB::statement('ALTER TABLE color_rack_products MODIFY new_product_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE color_rack_products MODIFY bundle_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('color_rack_products', function (Blueprint $table) {
            $table->dropUnique('color_rack_products_rack_product_unique');
            $table->dropUnique('color_rack_products_rack_bundle_unique');
        });
    }
};
