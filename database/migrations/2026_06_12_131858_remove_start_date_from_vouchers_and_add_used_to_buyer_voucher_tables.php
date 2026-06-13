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
        Schema::table('buyer_voucher', function (Blueprint $table) {
            $table->dropColumn('start_date');
            $table->integer('used')->default(0)->after('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_voucher', function (Blueprint $table) {
            $table->dateTime('start_date')->nullable();
            $table->dropColumn('used');
        });
    }
};
