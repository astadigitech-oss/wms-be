<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approve_queues', function (Blueprint $table) {
            $table->unsignedBigInteger('notification_id')
                ->nullable()
                ->after('product_id');

            $table->foreign('notification_id')
                ->references('id')
                ->on('notifications')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approve_queues', function (Blueprint $table) {
            $table->dropForeign(['notification_id']);
            $table->dropColumn('notification_id');
        });
    }
};
