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
        Schema::create('sku_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('sku_product_old_id')->constrained()->cascadeOnDelete();
            $table->integer('actual_quantity_batch')->default(0);
            $table->integer('damaged_quantity_batch')->default(0);
            $table->enum('type', ['entry', 'rollback'])->default('entry');
            $table->text('note')->default('-');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sku_batches');
    }
};
