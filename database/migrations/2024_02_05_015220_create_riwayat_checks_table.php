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
        Schema::create('riwayat_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('code_document');
            $table->string('base_document');
            $table->integer('total_data');
            $table->integer('total_data_in');
            $table->integer('total_data_lolos');
            $table->integer('total_data_damaged');
            $table->integer('total_data_abnormal');
            $table->integer('total_discrepancy');
            $table->enum('status_approve', ['done', 'pending', 'staging','display']);

            // Tambahkan kolom untuk persentase
            $table->decimal('precentage_total_data', 5, 2)->nullable(); // Menyimpan persentase data in
            $table->decimal('percentage_in', 5, 2)->nullable(); // Menyimpan persentase data in
            $table->decimal('percentage_lolos', 5, 2)->nullable(); // Menyimpan persentase data lolos
            $table->decimal('percentage_damaged', 5, 2)->nullable(); // Menyimpan persentase data damaged
            $table->decimal('percentage_abnormal', 5, 2)->nullable(); // Menyimpan persentase data abnormal
            $table->decimal('percentage_discrepancy', 5, 2)->nullable(); // Menyimpan persentase discrepancy

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat_checks');
    }
};
