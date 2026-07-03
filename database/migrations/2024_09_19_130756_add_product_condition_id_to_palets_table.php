<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{   
     public function up(): void
     {
         Schema::table('palets', function (Blueprint $table) {
             $table->foreignId('product_condition_id')->nullable()->constrained();
         });
     }
 
     public function down(): void
     {
         Schema::table('palets', function (Blueprint $table) {
             $table->dropColumn('product_condition_id');
         });
     }
};
