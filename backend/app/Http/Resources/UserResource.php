<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * @mixin User
 */
class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'popia_consent_version' => $this->popia_consent_version,
            'popia_consented_at' => $this->popia_consented_at?->toISOString(),
            'ai_extraction_consent' => (bool) $this->ai_extraction_consent,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
