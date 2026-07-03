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
        // Menambahkan constraint UNIQUE kembali
        Schema::table('product_approves', function (Blueprint $table) {
            $table->unique('new_barcode_product'); // Tambahkan unique constraint
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Menghapus constraint UNIQUE
        Schema::table('product_approves', function (Blueprint $table) {
            $table->dropUnique(['new_barcode_product']); // Hapus unique constraint
        });
    }
};
