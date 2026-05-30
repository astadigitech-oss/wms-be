<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(DB::select("SHOW INDEX FROM movement_products"))->pluck('Key_name')->unique();

        if (!$indexes->contains('idx_mp_product_datetime_created')) {
            Schema::table('movement_products', function (Blueprint $table) {
                $table->index(['product_id', 'dateTime', 'created_at'], 'idx_mp_product_datetime_created');
            });
        }

        if (!$indexes->contains('idx_mp_datetime_product_created')) {
            Schema::table('movement_products', function (Blueprint $table) {
                $table->index(['dateTime', 'product_id', 'created_at'], 'idx_mp_datetime_product_created');
            });
        }

        if (!$indexes->contains('idx_mp_to')) {
            Schema::table('movement_products', function (Blueprint $table) {
                $table->index(['to'], 'idx_mp_to');
            });
        }
    }

    public function down(): void
    {
        Schema::table('movement_products', function (Blueprint $table) {
            $table->dropIndex('idx_mp_product_datetime_created');
            $table->dropIndex('idx_mp_datetime_product_created');
            $table->dropIndex('idx_mp_to');
        });
    }
};
