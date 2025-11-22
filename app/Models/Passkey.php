<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Passkey extends Pivot
{

    protected $table = 'passkeys';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
