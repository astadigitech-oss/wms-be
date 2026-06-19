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
        Schema::create('cogs_references', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('cogs_channel_id')
                ->constrained('cogs_channels', 'id')
                ->onDelete('cascade');

            $table->enum('type', ['sku', 'reguler'])->default('reguler');

            $table->unsignedBigInteger('document_id');

            $table->foreignId('user_id')
                ->constrained('users', 'id')
                ->onDelete('cascade');

            $table->timestamps();

            $table->unique(['document_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cogs_references');
    }
};
