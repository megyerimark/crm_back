<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('hu')->after('password');
            $table->string('theme', 10)->default('light')->after('locale');
            $table->string('timezone')->nullable()->after('theme');
            $table->boolean('is_super_admin')->default(false)->after('timezone');
            $table->foreignId('current_workspace_id')->nullable()->after('is_super_admin');
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'theme', 'timezone', 'is_super_admin', 'current_workspace_id', 'last_login_at']);
        });
    }
};
