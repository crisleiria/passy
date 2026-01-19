<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Passkey;
use App\Support\JsonSerializer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredentialSource;

class WebAuthnAuthController extends Controller
{
    private function getSecureHost(Request $request): string
    {
        // For localhost, return just 'localhost' without port
        $host = $request->getHost();
        return str_replace(':8000', '', $host);
    }

    /**
     * Get registration options for WebAuthn.
     */
    public function registerOptions(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
        ]);

        $userId = Str::uuid()->toString();

        $options = new PublicKeyCredentialCreationOptions(
            rp: new PublicKeyCredentialRpEntity(
                name: config('app.name'),
                id: parse_url(config('app.url'), PHP_URL_HOST),
            ),
            user: new PublicKeyCredentialUserEntity(
                name: $request->email,
                id: $userId,
                displayName: $request->name,
            ),
            challenge: Str::random(32),
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            )
        );

        Session::put('webauthn_register', [
            'options' => $options,
            'name' => $request->name,
            'email' => $request->email,
            'user_id' => $userId,
        ]);

        return JsonSerializer::serialize($options);
    }

    /**
     * Complete registration with WebAuthn credential.
     */
    public function register(Request $request)
    {
        $request->validate([
            'credential' => 'required|string',
            'encrypted_master_key' => 'required|string',
            'pin_salt' => 'required|string',
        ]);

        $sessionData = Session::get('webauthn_register');
        if (!$sessionData) {
            return response()->json(['error' => 'Registration session expired'], 400);
        }

        $publicKeyCredential = JsonSerializer::deserialize(
            $request->credential,
            PublicKeyCredential::class
        );

        if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            return response()->json(['error' => 'Invalid credential response'], 400);
        }

        try {
            $factory = new CeremonyStepManagerFactory();
            // Allow localhost for development
            $factory->setAllowedOrigins(['http://localhost:8000', 'http://localhost','https://passy.test'], true);

            $publicKeyCredentialSource = AuthenticatorAttestationResponseValidator::create(
                $factory->creationCeremony()
            )->check(
                authenticatorAttestationResponse: $publicKeyCredential->response,
                publicKeyCredentialCreationOptions: $sessionData['options'],
                host: $this->getSecureHost($request),
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid passkey: ' . $e->getMessage()], 400);
        }

        // Create user without password
        $user = User::create([
            'name' => $sessionData['name'],
            'email' => $sessionData['email'],
            'password' => null, // No password needed
            'encrypted_master_key' => $request->encrypted_master_key,
            'pin_salt' => $request->pin_salt,
        ]);

        // Store passkey
        $user->passkeys()->create([
            'name' => 'Primary Passkey',
            'credential_id' => $publicKeyCredential->rawId,
            'data' => JsonSerializer::serialize($publicKeyCredentialSource),
        ]);

        Session::forget('webauthn_register');
        Auth::login($user);

        return response()->json([
            'success' => true,
            'redirect' => route('passwords'),
        ]);
    }

    /**
     * Get authentication options for WebAuthn.
     */
    public function loginOptions(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $passkey = $user->passkeys()->first();
        if (!$passkey) {
            return response()->json(['error' => 'No passkey registered'], 404);
        }

        $options = new PublicKeyCredentialRequestOptions(
            challenge: Str::random(32),
            rpId: parse_url(config('app.url'), PHP_URL_HOST),
        );

        Session::put('webauthn_login', [
            'options' => $options,
            'user_id' => $user->id,
        ]);

        return JsonSerializer::serialize($options);
    }

    /**
     * Complete authentication with WebAuthn.
     */
    public function login(Request $request)
    {
        $request->validate([
            'credential' => 'required|string',
            'email' => 'required|email',
        ]);

        $sessionData = Session::get('webauthn_login');
        if (!$sessionData) {
            return response()->json(['error' => 'Login session expired'], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $publicKeyCredential = JsonSerializer::deserialize(
            $request->credential,
            PublicKeyCredential::class
        );

        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            return response()->json(['error' => 'Invalid credential response'], 400);
        }

        // Find matching passkey
        $passkey = Passkey::where('credential_id', $publicKeyCredential->rawId)->first();
        if (!$passkey || $passkey->user_id !== $user->id) {
            return response()->json(['error' => 'Invalid passkey'], 400);
        }

        try {
            $factory = new CeremonyStepManagerFactory();
            $factory->setAllowedOrigins(['http://localhost:8000', 'http://localhost','https://passy.test'], true);

            $publicKeyCredentialSource = AuthenticatorAssertionResponseValidator::create(
                $factory->requestCeremony()
            )->check(
                publicKeyCredentialSource: JsonSerializer::deserialize($passkey->data, PublicKeyCredentialSource::class),
                authenticatorAssertionResponse: $publicKeyCredential->response,
                publicKeyCredentialRequestOptions: $sessionData['options'],
                host: $this->getSecureHost($request),
                userHandle: null,
            );

            // Update passkey data
            $passkey->update([
                'data' => JsonSerializer::serialize($publicKeyCredentialSource),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }

        Session::forget('webauthn_login');
        Auth::login($user);

        return response()->json([
            'success' => true,
            'encrypted_master_key' => $user->encrypted_master_key,
            'pin_salt' => $user->pin_salt,
        ]);
    }

    /**
     * Login with Master Key (backup method).
     */
    public function loginWithMasterKey(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Login user - Master Key validation happens client-side
        Auth::login($user);

        return response()->json([
            'success' => true,
            'redirect' => route('passwords'),
        ]);
    }

    /**
     * Get encryption data for vault unlock.
     */
    public function getEncryptionData(Request $request)
    {
        $user = Auth::user();

        if (!$user->encrypted_master_key || !$user->pin_salt) {
            return response()->json([
                'hasEncryption' => false,
            ]);
        }

        return response()->json([
            'hasEncryption' => true,
            'encrypted_master_key' => $user->encrypted_master_key,
            'pin_salt' => $user->pin_salt,
        ]);
    }

    /**
     * Reset PIN using Master Key.
     * The client verifies the Master Key and sends new encrypted data.
     */
    public function resetPin(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'encrypted_master_key' => 'required|string',
            'pin_salt' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Log before update
        \Log::info('Before PIN reset:', [
            'email' => $validated['email'],
            'old_salt' => $user->pin_salt,
            'new_salt' => $validated['pin_salt'],
            'old_key_length' => strlen($user->encrypted_master_key ?? ''),
            'new_key_length' => strlen($validated['encrypted_master_key']),
        ]);

        // Update user with new encrypted master key and salt
        $updated = $user->update([
            'encrypted_master_key' => $validated['encrypted_master_key'],
            'pin_salt' => $validated['pin_salt'],
        ]);

        // Refresh to get latest data
        $user->refresh();

        // Log after update
        \Log::info('After PIN reset:', [
            'updated' => $updated,
            'new_salt_in_db' => $user->pin_salt,
            'new_key_length_in_db' => strlen($user->encrypted_master_key ?? ''),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'PIN reset successfully',
            'debug' => [
                'salt_updated' => $user->pin_salt === $validated['pin_salt'],
                'key_updated' => $user->encrypted_master_key === $validated['encrypted_master_key'],
            ]
        ]);
    }
}

