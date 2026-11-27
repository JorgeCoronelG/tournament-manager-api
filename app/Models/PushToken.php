<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de notificaciones push de Expo (ExponentPushToken[...]) de un celular con la app.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string|null $platform
 */
class PushToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
