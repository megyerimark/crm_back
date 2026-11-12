<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin console: support tickets, daily activity for analytics, and plan overrides
 * (limits, features and prices editable without a deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('category', 20)->default('question');
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->string('plan', 20)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_customer_reply_at')->nullable();
            $table->timestamp('last_staff_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_staff')->default(false);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('user_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unique(['user_id', 'day']);
            $table->index('day');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::create('plan_settings', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 20)->unique();
            $table->json('overrides');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_seen_at'));
        Schema::dropIfExists('user_activity_days');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
