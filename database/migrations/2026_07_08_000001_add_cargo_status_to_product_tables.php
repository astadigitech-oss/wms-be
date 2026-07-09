<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $statuses = "'display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete', 'slow_moving', 'scrap_qcd', 'cargo'";
        $bundleStatuses = "'not sale', 'sale', 'bundle', 'migrate', 'cargo'";

        DB::statement("ALTER TABLE new_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        DB::statement("ALTER TABLE staging_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        DB::statement("ALTER TABLE bkl_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        DB::statement("ALTER TABLE bundles MODIFY COLUMN product_status ENUM($bundleStatuses) NULL");
    }

    public function down(): void
    {
        $statuses = "'display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete', 'slow_moving', 'scrap_qcd'";
        $bundleStatuses = "'sale', 'not sale', 'bundle', 'migrate'";

        DB::statement("ALTER TABLE new_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        DB::statement("ALTER TABLE staging_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        DB::statement("ALTER TABLE bkl_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        DB::statement("ALTER TABLE bundles MODIFY COLUMN product_status ENUM($bundleStatuses) NULL");
    }
};
