<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('palets', function (Blueprint $table) {

            $table->foreignId('warehouse_id')->constrained()->after('is_active');
            $table->string('warehouse_name')->after('warehouse_id');
            $table->foreignId('product_condition_id')->constrained()->after('warehouse_name');
            $table->string('product_condition_name')->after('product_condition_id');
            $table->foreignId('product_status_id')->constrained()->after('product_condition_name');
            $table->string('product_status_name')->after('product_status_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('palets', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
            $table->dropColumn('warehouse_name');
            $table->dropForeign(['product_condition_id']);
            $table->dropColumn('product_condition_id');
            $table->dropColumn('product_condition_name');
            $table->dropForeign(['product_status_id']);
            $table->dropColumn('product_status_id');
            $table->dropColumn('product_status_name');
        });
    }
};
