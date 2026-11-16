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
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('payment_qr')->nullable();
        });

        Schema::create('invoice_bank_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->string('iban', 24);
            $table->timestamps();
            $table->unique(['company_id', 'iban']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_bank_accounts');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_qr');
        });
    }
};
