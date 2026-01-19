<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds encryption fields for PIN-based Master Key protection
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted master key (wrapped with PIN-derived key)
            $table->text('encrypted_master_key')->nullable()->after('password');
            // Salt for PBKDF2 key derivation
            $table->string('pin_salt')->nullable()->after('encrypted_master_key');
            // Make password nullable (not used with WebAuthn)
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['encrypted_master_key', 'pin_salt']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
