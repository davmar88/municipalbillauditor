<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $organisation_id The account they are working in now.
 * @property bool $is_staff
 * @property Organisation|null $organisation
 * @property Collection<int, Organisation> $organisations
 * @property string $name
 * @property string $email
 * @property string|null $google_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'is_staff', 'google_id'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** The accounts someone being deleted belonged to, for the purge after. */
    protected array $accountsLeft = [];

    protected static function booted(): void
    {
        // Whichever account someone works in, they belong to it.
        static::saved(function (User $user) {
            if ($user->organisation_id !== null && ($user->wasRecentlyCreated || $user->wasChanged('organisation_id'))) {
                $user->organisations()->syncWithoutDetaching([$user->organisation_id]);
            }
        });

        // POPIA: when the last person on an account deletes their profile,
        // the account's bills, files and disputes go with it. Each of their
        // accounts is checked.
        static::deleting(function (User $user) {
            $user->accountsLeft = $user->organisations()->pluck('organisations.id')
                ->push($user->organisation_id)->filter()->unique()->values()->all();
            $user->organisations()->detach();
        });

        static::deleted(function (User $user) {
            foreach (Organisation::query()->whereKey($user->accountsLeft)->get() as $organisation) {
                if (! $organisation->users()->exists()) {
                    $organisation->purge();
                }
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_staff' => 'boolean',
        ];
    }

    /**
     * The account they are working in now. Everything they see and do is
     * scoped to it.
     *
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * Every account they belong to: a home, a rental portfolio, a business.
     *
     * @return BelongsToMany<Organisation, $this>
     */
    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class)->withTimestamps()->orderBy('organisation_user.id');
    }

    public function belongsToOrganisation(Organisation $organisation): bool
    {
        return $this->organisations()->whereKey($organisation->id)->exists();
    }

    /** Work in another of their accounts. */
    public function switchTo(Organisation $organisation): void
    {
        $this->organisation()->associate($organisation)->save();
    }
}
