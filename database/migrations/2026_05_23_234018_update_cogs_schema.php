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
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('cogs_reference');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('suppliers');

        Schema::enableForeignKeyConstraints();

        Schema::create('cogs_supplier', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('cogs_channel', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->unique();
            $table->string('supplier_id');
            $table->enum('type', ['percentage', 'unit']);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->foreign('supplier_id')->references('id')->on('cogs_supplier')->onDelete('cascade');
        });

        Schema::create('cogs_reference', function (Blueprint $table) {
            $table->string('channel_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id')->nullable(); 
            $table->timestamp('created_at')->useCurrent(); 

            $table->primary(['channel_id', 'document_id']);

            $table->foreign('channel_id')->references('id')->on('cogs_channel')->onDelete('cascade');
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('cogs_reference');
        Schema::dropIfExists('cogs_channel');
        Schema::dropIfExists('cogs_supplier');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade')->onUpdate('cascade');
            $table->string('name');
            $table->enum('type', ['percentage', 'unit']);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('cogs_reference', function (Blueprint $table) {
            $table->foreignId('cogs_id')->constrained('channels')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
            $table->primary(['cogs_id', 'document_id']);
        });

        Schema::enableForeignKeyConstraints();
    }
};