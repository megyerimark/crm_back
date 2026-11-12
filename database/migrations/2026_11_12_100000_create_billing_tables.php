<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Cashier (Stripe) tables with the workspace as the billable model,
 * billing details for Hungarian invoices and the issued invoice log (Számlázz.hu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->string('billing_interval', 10)->nullable();
            $table->string('intended_plan', 20)->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_country', 2)->nullable();
            $table->string('billing_postcode', 20)->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_address')->nullable();
            $table->string('billing_tax_number', 30)->nullable();
            $table->string('billing_eu_vat_number', 30)->nullable();
            $table->timestamp('trial_reminder_sent_at')->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'stripe_price']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('stripe_number')->nullable();
            $table->string('number')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('plan', 20)->nullable();
            $table->string('interval', 10)->nullable();
            $table->string('currency', 3);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('gross_amount', 14, 2);
            $table->string('vat_key', 10);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('buyer');
            $table->string('pdf_path')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn([
                'stripe_id', 'pm_type', 'pm_last_four', 'billing_interval', 'intended_plan', 'billing_name', 'billing_email', 'billing_country',
                'billing_postcode', 'billing_city', 'billing_address', 'billing_tax_number', 'billing_eu_vat_number', 'trial_reminder_sent_at',
            ]);
        });
    }
};
