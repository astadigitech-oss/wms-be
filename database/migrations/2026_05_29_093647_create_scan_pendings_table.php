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
        Schema::create('scan_pendings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_old_id')->constrained('product_olds')->onDelete('cascade');
            $table->string("edited_name")->nullable();
            $table->integer("edited_qty")->nullable();
            $table->enum("status", ["pending", "approved", "rejected"])->default("pending");
            $table->foreignId('editor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->text('reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_pendings');
    }
};
