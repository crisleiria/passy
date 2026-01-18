<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

class Password extends Model //implements CipherSweetEncrypted
{
    use HasFactory;//, UsesCipherSweet;

    protected $fillable = [
        'domain',
        'username',
        'password',
        'icon_path',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('password')
            ->addField('username')
            ->addBlindIndex('password', new BlindIndex('password_index'))
            ->addBlindIndex('username', new BlindIndex('username_index'));
    }
}
