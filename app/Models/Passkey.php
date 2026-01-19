<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Passkey extends Pivot
{

    protected $table = 'passkeys';

    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'credential_id',
        'data',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
