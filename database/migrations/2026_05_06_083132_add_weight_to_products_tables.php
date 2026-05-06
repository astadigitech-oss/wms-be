<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_products', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
        });

        Schema::table('staging_products', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
        });

        Schema::table('product__filters', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
        });

        Schema::table('product__bundles', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
        });

        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('new_products', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('staging_products', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('product__filters', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('product__bundles', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};