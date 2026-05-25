<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Generated column 'quality_lolos' meng-extract nilai $.lolos dari new_quality.
     *
     * Ada dua format data new_quality di DB:
     * 1. Native JSON object  : {"lolos":"lolos"}        → pattern pertama berhasil
     * 2. Doubly-encoded string: "{\"lolos\":\"lolos\"}"  → fallback ke pattern kedua
     *
     * COALESCE menangani keduanya tanpa perlu OR di setiap query.
     */
    public function up(): void
    {
        $expression = "COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')), 'null'),
            JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos'))
        )";

        // staging_products
        // Covering index: semua WHERE columns + new_price_product
        // Memungkinkan MySQL jawab COUNT(*)+SUM(price) langsung dari index tanpa baca tabel
        $stagingCols = collect(DB::select("SHOW COLUMNS FROM staging_products"))->pluck('Field');
        if (!$stagingCols->contains('quality_lolos')) {
            DB::statement("
                ALTER TABLE staging_products
                ADD COLUMN quality_lolos VARCHAR(20) GENERATED ALWAYS AS ({$expression}) STORED,
                ADD INDEX idx_staging_saldo (quality_lolos, new_status_product, new_tag_product, new_price_product)
            ");
        }

        // new_products
        // Composite index: quality_lolos + new_status_product + new_tag_product + new_category_product
        // Covers both display_reguler dan display_color query sekaligus
        $newCols = collect(DB::select("SHOW COLUMNS FROM new_products"))->pluck('Field');
        if (!$newCols->contains('quality_lolos')) {
            DB::statement("
                ALTER TABLE new_products
                ADD COLUMN quality_lolos VARCHAR(20) GENERATED ALWAYS AS ({$expression}) STORED,
                ADD INDEX idx_new_saldo (quality_lolos, new_status_product, new_tag_product, new_category_product)
            ");
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE staging_products DROP INDEX idx_staging_saldo, DROP COLUMN quality_lolos");
        DB::statement("ALTER TABLE new_products DROP INDEX idx_new_saldo, DROP COLUMN quality_lolos");
    }
};
