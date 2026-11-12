<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quote (ajánlat) tracking: line items, public acceptance link, timeline and follow-up reminders.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('title');
            $table->string('status', 20)->default('draft');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('currency', 3)->default('HUF');
            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->text('introduction')->nullable();
            $table->text('terms')->nullable();
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by_name')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->unsignedTinyInteger('follow_up_count')->default(0);
            $table->string('source', 30)->default('manual');
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'follow_up_at']);
            $table->unique(['workspace_id', 'source', 'external_id']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(27);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedTinyInteger('quote_follow_up_days')->default(3)->after('locale');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_quote_updates')->default(true)->after('notify_new_leads');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notify_quote_updates'));
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('quote_follow_up_days'));
        Schema::dropIfExists('quote_events');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
