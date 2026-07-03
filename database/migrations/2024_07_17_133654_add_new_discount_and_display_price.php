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
        Schema::table('new_products', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('product__filters', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('product__bundles', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('palet_filters', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('palet_products', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('repair_products', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('repair_filters', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('product_approves', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('product_qcds', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('filter_qcds', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
        });
    }

    /**
     * Reverse the migrations.
     */

    public function down(): void
    {
        Schema::table('new_products', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('product__filters', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('product__bundles', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('palet_filters', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('palet_products', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('repair_products', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('repair_filters', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('product_approves', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('product_qcds', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('filter_qcds', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('new_discount');
            $table->dropColumn('display_price');
        });
    }
};
