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
        Schema::table('product__filters', function (Blueprint $table) {
            $table->string('actual_new_quality')->nullable();
            $table->string('actual_old_price_product')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product__filters', function (Blueprint $table) {
            $table->dropColumn('actual_new_quality');
            $table->dropColumn('actual_old_price_product');
        });
    }
};
