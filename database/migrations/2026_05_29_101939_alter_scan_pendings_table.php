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
        Schema::table('scan_pendings', function (Blueprint $table) {
            // 1. Tambah kolom source_model (misal: tipe string dan boleh null)
            $table->string('source_model')->nullable()->after('id'); // sesuaikan 'after' nya

            // 2. Hapus foreign key lama (asumsi nama constraint-nya 'scan_pendings_product_old_id_foreign')
            // Jika dulu pakai $table->foreignId('product_old_id')->constrained(), gunakan ini:
            $table->dropForeign(['product_old_id']);

            // 3. Ubah nama kolom dari product_old_id menjadi source_id
            $table->renameColumn('product_old_id', 'source_id');
        });

        Schema::table('scan_pendings', function (Blueprint $table) {
            // 4. Ubah tipe data source_id jika diperlukan (menghilangkan attribute unsigned/foreign)
            // Di Laravel 10+, change() otomatis mendeteksi perubahan tanpa perlu package doctrine/dbal
            $table->bigInteger('source_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scan_pendings', function (Blueprint $table) {
            // Kembalikan nama kolom
            $table->renameColumn('source_id', 'product_old_id');

            // Hapus kolom source_model
            $table->dropColumn('source_model');
        });

        Schema::table('scan_pendings', function (Blueprint $table) {
            // Pasang kembali foreign key jika di-rollback (sesuaikan nama tabel relasi aslinya, misal: products)
            $table->foreign('product_old_id')->references('id')->on('products')->onDelete('cascade');
        });
    }
};
