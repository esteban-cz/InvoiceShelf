<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->string('registration_number', 32)->nullable());
        Schema::create('fio_connections', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('creator_id')->index();
            $table->string('name', 120);
            $table->text('token');
            $table->string('iban', 34);
            $table->string('currency_code', 3);
            $table->unsignedInteger('payment_method_id')->nullable()->index();
            $table->boolean('enabled')->default(true);
            $table->boolean('auto_match')->default(true);
            $table->date('sync_from');
            $table->date('imported_through')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'iban', 'currency_code']);
        });
        Schema::create('fio_transactions', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('fio_connection_id')->nullable()->index();
            $table->string('account_iban', 34);
            $table->string('external_id', 40);
            $table->date('transaction_date');
            $table->bigInteger('amount');
            $table->string('currency_code', 3);
            $table->string('variable_symbol', 10)->nullable();
            $table->string('counterparty_account', 255)->nullable();
            $table->string('counterparty_name', 255)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->string('reason', 40)->nullable();
            $table->unsignedInteger('payment_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['company_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fio_transactions');
        Schema::dropIfExists('fio_connections');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('registration_number'));
    }
};
