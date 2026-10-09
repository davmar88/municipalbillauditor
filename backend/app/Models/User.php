<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'popia_consent_version', 'popia_consented_at', 'ai_extraction_consent'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Version of the POPIA privacy notice and consent wording the user
     * agreed to at registration. Bump it when the wording changes.
     */
    public const POPIA_CONSENT_VERSION = '2026-10-v1';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'popia_consented_at' => 'datetime',
            'ai_extraction_consent' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * @return HasManyThrough<Bill, Property, $this>
     */
    public function bills(): HasManyThrough
    {
        return $this->hasManyThrough(Bill::class, Property::class);
    }
}
