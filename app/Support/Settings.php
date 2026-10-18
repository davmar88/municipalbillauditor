<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * What the owner typed in on the admin page: the payment and Claude keys,
 * whether Pineapple packs are on sale and whether cash-outs are open. Read once
 * per request.
 */
final class Settings
{
    /** Keys whose values are secret: the page only ever shows their last four characters. */
    public const SECRETS = ['paystack_secret_key', 'paypal_client_secret', 'anthropic_api_key'];

    public const KEYS = [
        'apples_on_sale',
        'cashout_open',
        'paystack_secret_key',
        'paypal_client_id',
        'paypal_client_secret',
        'paypal_mode',
        'anthropic_api_key',
    ];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->all()[$key] ?? null;

        return filled($value) ? $value : $default;
    }

    public function on(string $key): bool
    {
        return $this->get($key) === '1';
    }

    /** Save a value, or forget it when it's empty. */
    public function put(string $key, ?string $value, ?User $by = null): void
    {
        $value = $value === null ? null : trim($value);

        if (blank($value)) {
            AppSetting::query()->where('key', $key)->delete();
        } else {
            AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
        }

        $this->values = null;
    }

    /** "••••7a3f" for a saved secret, null when there is none. */
    public function hint(string $key): ?string
    {
        $value = $this->get($key);

        return $value === null ? null : '••••'.mb_substr($value, -4);
    }

    /** @return array<string, string> */
    private function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $values = [];

        foreach (AppSetting::query()->get(['key', 'value']) as $setting) {
            try {
                $values[$setting->key] = (string) $setting->value;
            } catch (DecryptException) {
                // Saved under another app key: it has to be typed in again.
                Log::warning('A saved setting could not be decrypted', ['key' => $setting->key]);
            }
        }

        return $this->values = $values;
    }
}
