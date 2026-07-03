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
        Schema::create('bulky_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulky_document_id')->constrained()->cascadeOnDelete();
            $table->string('barcode_bulky_sale');
            $table->string('product_category_bulky_sale')->nullable();
            $table->string('name_product_bulky_sale');
            $table->decimal('old_price_bulky_sale', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulky_sales');
    }
};
