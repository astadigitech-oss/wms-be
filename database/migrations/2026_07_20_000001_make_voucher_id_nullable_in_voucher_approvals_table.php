<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_approvals', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
        });

        DB::statement('ALTER TABLE voucher_approvals MODIFY voucher_id BIGINT UNSIGNED NULL');

        Schema::table('voucher_approvals', function (Blueprint $table) {
            $table->foreign('voucher_id')
                ->references('id')
                ->on('vouchers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_approvals', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
        });

        DB::statement('ALTER TABLE voucher_approvals MODIFY voucher_id BIGINT UNSIGNED NOT NULL');

        Schema::table('voucher_approvals', function (Blueprint $table) {
            $table->foreign('voucher_id')
                ->references('id')
                ->on('vouchers')
                ->cascadeOnDelete();
        });
    }
};
