<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Wraps one entry of config/metros.php (with its code).
 */
class MetroResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metro = $this->resource;

        return [
            'code' => $metro['code'],
            'name' => $metro['name'],
            'dispute_window_days' => (int) $metro['dispute_window_days'],
            'response_wait_days' => (int) $metro['response_wait_days'],
            'dispute_channels' => array_map(fn (array $c) => [
                'type' => $c['type'],
                'label' => $c['label'],
                'value' => $c['value'],
            ], $metro['dispute_channels']),
            'escalation_steps' => array_map(fn (array $s) => [
                'level' => (int) $s['level'],
                'name' => $s['name'],
                'description' => $s['description'],
            ], $metro['escalation_steps']),
            'verified' => (bool) $metro['verified'],
        ];
    }
}
