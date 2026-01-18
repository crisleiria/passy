<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LogDriverPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_email_is_written_to_logs()
    {
        // Set mail driver to log
        Config::set('mail.default', 'log');
        Config::set('mail.mailers.log.transport', 'log');
        Config::set('mail.mailers.log.channel', null);

        // Forget resolved instances to force re-creation with new config
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');

        // Clear log file to ensure clean state
        $logFile = storage_path('logs/laravel.log');
        File::put($logFile, '');

        $user = User::factory()->create();

        // Trigger password reset
        $response = $this->post(route('password.email'), ['email' => $user->email]);

        $response->assertStatus(302);
        $response->assertSessionHas('status');

        // Verify log content
        $logContent = File::get($logFile);

        $this->assertStringContainsString('Reset Password', $logContent);
        $this->assertStringContainsString('/reset-password/', $logContent); 
    }
}
