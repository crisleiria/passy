<?php

namespace App\Http\Controllers\Settings;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\Passkey;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Support\JsonSerializer;
use Webauthn\Exception\InvalidDataException;
use Webauthn\PublicKeyCredential;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Illuminate\Validation\ValidationException;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;

class PassKeyController extends Controller
{
    /**
     * Show the user's passkeys settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Passkeys', [
            'passkeys' => $request->user()->passkeys,
        ]);
    }

    /**
     * @throws InvalidDataException
     */
    public function registerOptions(Request $request)
    {
        $options = new PublicKeyCredentialCreationOptions(
            rp: new PublicKeyCredentialRpEntity(
                name: config('app.name'),
                id: parse_url(config('app.url'), PHP_URL_HOST),
            ),
            user: new PublicKeyCredentialUserEntity(
                name: $request->user()->email,
                id: $request->user()->id,
                displayName: $request->user()->name,
            ),
            challenge: Str::random(),
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
            )
        );

        Session::flash('passkey-registration-options', $options);

        return JsonSerializer::serialize($options);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'passkey' => 'required|json',
        ]);

        $publicKeyCredential = JsonSerializer::deserialize($validated['passkey'], PublicKeyCredential::class);

        if(!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            return to_route('login');
        }

        try {
            $publicKeyCredentialSource = AuthenticatorAttestationResponseValidator::create(
                (new CeremonyStepManagerFactory)->creationCeremony()
            )->check(
                authenticatorAttestationResponse: $publicKeyCredential->response,
                publicKeyCredentialCreationOptions: Session::get('passkey-registration-options'),
                host: $request->getHost(),
            );
        } catch (\Throwable $e) {

            throw ValidationException::withMessages([
                'name' => 'Then given passkey is invalid',
            ])->errorBag('name');
        }

        $request->user()->passkeys()->create([
            'name' => $validated['name'],
            'credential_id' => json_decode($validated['passkey'])->rawId,
            'data' => JsonSerializer::serialize($publicKeyCredentialSource)
        ]);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(\Illuminate\Http\Request $request, \App\Models\Passkey $passkey)
    {
        if ($request->user()->id !== $passkey->user_id) {
            abort(403);
        }

        $passkey->delete();

        return back();
    }

    /**
     * @throws InvalidDataException
     */
    public function authenticateOptions()
    {
        $options = new PublicKeyCredentialRequestOptions(
            challenge: Str::random(),
            rpId: parse_url(config('app.url'), PHP_URL_HOST));

        Session::flash('passkey-authentication-options', $options);

        return JsonSerializer::serialize($options);
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate([
            'answer' => 'required|json',
        ]);

        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = JsonSerializer::deserialize($data['answer'], PublicKeyCredential::class);

        if(!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            return to_route('profile.edit');
        }

        $passKey = Passkey::firstWhere('credential_id', json_decode($data['answer'])->rawId);

        if(!$passKey) {
            throw ValidationException::withMessages([
                'answer' => 'Then given passkey is invalid',
            ])->errorBag('answer');
        }

       //dd(JsonSerializer::deserialize($passKey->data, PublicKeyCredentialSource::class));

        try {
            $publicKeyCredencialSource = AuthenticatorAssertionResponseValidator::create(
                new CeremonyStepManagerFactory()->requestCeremony()
            )->check(
                publicKeyCredentialSource: JsonSerializer::deserialize($passKey->data, PublicKeyCredentialSource::class),
                authenticatorAssertionResponse: $publicKeyCredential->response,
                publicKeyCredentialRequestOptions: Session::get('passkey-authentication-options'),
                host: $request->getHost(),
                userHandle: null);
        } catch (\Throwable $e) {

            Log::error($e);

            throw ValidationException::withMessages([
                'answer' => 'Then given passkey is invalid',
            ])->errorBag('answer');
        }

        $passKey->update([
            'data' => JsonSerializer::serialize($publicKeyCredencialSource)
        ]);

        Auth::loginUsingId($passKey->user_id);

        $request->session()->regenerate();

        return route('passwords');
    }

}
