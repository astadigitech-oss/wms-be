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
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
        });

        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
