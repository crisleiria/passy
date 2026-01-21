<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds master_key_hash for validating Master Key login
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SHA-256 hash of Master Key (computed client-side)
            $table->string('master_key_hash', 64)->nullable()->after('pin_salt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('master_key_hash');
        });
    }
};
