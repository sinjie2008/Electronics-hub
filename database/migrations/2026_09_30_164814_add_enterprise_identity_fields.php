<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        Schema::table('roles', fn (Blueprint $table) => $table->boolean('is_system')->default(false));
        Schema::table('permissions', fn (Blueprint $table) => $table->boolean('is_system')->default(false));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('is_system'));
        Schema::table('permissions', fn (Blueprint $table) => $table->dropColumn('is_system'));
    }
};
