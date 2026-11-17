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
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('sign_document')->default(false);
            $table->json('document_signature')->nullable();
        });

        Schema::table('invoice_bank_accounts', function (Blueprint $table): void {
            $table->string('bank_name', 120)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['sign_document', 'document_signature']));
        Schema::table('invoice_bank_accounts', fn (Blueprint $table) => $table->dropColumn('bank_name'));
    }
};
