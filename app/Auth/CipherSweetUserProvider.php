<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Support\Arrayable;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

class CipherSweetUserProvider extends EloquentUserProvider
{
    /**
     * Retrieve a user by the given credentials.
     *
     * @param  array  $credentials
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByCredentials(array $credentials)
    {
        $credentials = array_filter(
            $credentials,
            fn ($key) => ! str_contains($key, 'password'),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($credentials)) {
            return null;
        }

        // First, check if the model is using CipherSweet
        $model = $this->createModel();

        if (! $model instanceof CipherSweetEncrypted) {
            return parent::retrieveByCredentials($credentials);
        }

        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } elseif ($key === 'email') { // Specific check for email to use whereBlind
                $query->whereBlind('email', 'email_index', $value);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }
}
