<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_voucher', function (Blueprint $table) {
            $table->dateTime('start_date')
                ->nullable()
                ->after('voucher_id');

            $table->boolean('status')
                ->default(true)
                ->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_voucher', function (Blueprint $table) {
            $table->dropColumn([
                'start_date',
                'status'
            ]);
        });
    }
};
