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
        Schema::create('product_olds', function (Blueprint $table) {
            $table->id(); 
            $table->string('code_document');
            $table->string('old_barcode_product');
            $table->string('old_name_product',1024);
            $table->integer('old_quantity_product');
            $table->decimal('old_price_product', 12, 2); 
    
            $table->timestamps();
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_olds');
    }
};
