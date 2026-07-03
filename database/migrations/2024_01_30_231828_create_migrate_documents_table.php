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
        Schema::create('migrate_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document_migrate')->unique();
            $table->string('destiny_document_migrate');
            $table->bigInteger('total_product_document_migrate');
            $table->enum('status_document_migrate', ['proses', 'selesai']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migrate_documents');
    }
};
