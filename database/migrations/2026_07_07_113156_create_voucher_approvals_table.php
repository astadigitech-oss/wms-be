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
        Schema::create('voucher_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');

            $table->foreignId('voucher_id')->constrained('vouchers');
            $table->foreignId('buyer_id')->constrained('buyers');
            $table->foreignId('sale_document_id')->constrained('sale_documents');

            $table->decimal('nominal', 15, 2);
            $table->text('usage')->nullable();

            $table->enum('status', ['approve', 'reject', 'pending'])
                ->default('pending');

            $table->timestamp('date_request');
            $table->timestamp('date_approved')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_approvals');
    }
};
