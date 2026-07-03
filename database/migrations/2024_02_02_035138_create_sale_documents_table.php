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
        Schema::create('sale_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('code_document_sale')->unique();
            $table->bigInteger('buyer_id_document_sale');
            $table->string('buyer_name_document_sale');
            $table->string('buyer_phone_document_sale');
            $table->string('buyer_address_document_sale');
            $table->bigInteger('total_product_document_sale');
            $table->decimal('total_price_document_sale', 15, 2);
            $table->enum('status_document_sale', ['proses', 'selesai']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_documents');
    }
};
