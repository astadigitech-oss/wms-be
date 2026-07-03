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
        Schema::table('buyers', function (Blueprint $table) {
            $table->enum('type_buyer', ['Biasa', 'Repeat', 'Reguler'])->after('address_buyer');
            $table->bigInteger('amount_transaction_buyer')->after('type_buyer');
            $table->decimal('amount_purchase_buyer', 15, 2)->after('amount_transaction_buyer');
            $table->decimal('avg_purchase_buyer', 15, 2)->after('amount_purchase_buyer');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn('type_buyer', 'amount_transaction_buyer', 'amount_purchase_buyer', 'avg_purchase_buyer');
        });
    }
};
