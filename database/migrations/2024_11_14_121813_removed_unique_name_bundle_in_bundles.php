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
        Schema::table('bundles', function (Blueprint $table) {
            // Menghapus constraint unique dari kolom name_bundle
            $table->dropUnique(['name_bundle']);
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            // Menambahkan kembali constraint unique ke kolom name_bundle jika dibutuhkan untuk rollback
            $table->unique('name_bundle');
        });
    }
};
