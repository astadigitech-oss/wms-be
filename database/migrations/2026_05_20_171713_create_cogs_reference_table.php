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
        Schema::create('cogs_reference', function (Blueprint $table) {
            $table->foreignId('cogs_id')->constrained('channels')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
            $table->primary(['cogs_id', 'document_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cogs_reference');
    }
};