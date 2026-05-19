<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('product_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('notification_id')->nullable()->index();
            $table->string('code_document')->index();
            $table->string('barcode_product')->index();
            $table->json('old_value')->nullable(); 
            $table->json('new_value')->nullable(); 
            $table->unsignedBigInteger('request_user_id'); 
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();
            
            $table->foreign('request_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('approver_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_edit_histories');
    }
};
