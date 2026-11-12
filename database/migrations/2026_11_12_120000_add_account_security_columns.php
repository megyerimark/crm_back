<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication, pending e-mail changes, notification preferences, and invoice retention:
 * invoices must be kept (Hungarian accounting law) even after the workspace / account is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
            $table->boolean('notify_task_reminders')->default(true);
            $table->boolean('notify_daily_digest')->default(true);
            $table->boolean('notify_new_leads')->default(true);
            $table->timestamp('last_digest_sent_at')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable()->change();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'pending_email', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step',
                'notify_task_reminders', 'notify_daily_digest', 'notify_new_leads', 'last_digest_sent_at',
            ]);
        });
    }
};
