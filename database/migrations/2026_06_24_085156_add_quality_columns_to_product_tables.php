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
        $tables = [
            'staging_products',
            'new_products',
            'bkl_products',
            'migrate_bulky_products',
            'product_approves',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('is_lolos')->nullable();
                $table->string('is_damaged')->nullable();
                $table->string('is_abnormal')->nullable();
                $table->string('is_non')->nullable();
                $table->boolean('is_adjusted_quality')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'staging_products',
            'new_products',
            'bkl_products',
            'migrate_bulky_products',
            'product_approves',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn([
                    'is_lolos',
                    'is_damaged',
                    'is_abnormal',
                    'is_non',
                    'is_adjusted_quality',
                ]);
            });
        }
    }
};
