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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('code_document_sale');
            $table->string('product_name_sale', 1024);
            $table->string('product_category_sale');
            $table->string('product_barcode_sale');
            $table->decimal('product_price_sale', 15, 2)->nullable();
            $table->bigInteger('product_qty_sale');
            $table->enum('status_sale', ['proses', 'selesai']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
