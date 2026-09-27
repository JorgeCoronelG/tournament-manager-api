<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    const UPDATED_AT = null;

    protected $table = 'password_reset_tokens';

    protected $primaryKey = 'email';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'code',
        'attempts',
        'expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
    ];
}
