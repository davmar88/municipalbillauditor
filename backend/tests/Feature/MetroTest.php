<?php

namespace Tests\Feature;

use Tests\TestCase;

class MetroTest extends TestCase
{
    public function test_lists_every_metro_publicly_with_default_unverified_settings(): void
    {
        $response = $this->getJson('/api/v1/metros')->assertOk();

        $metros = $response->json('data');
        $this->assertSame(
            ['johannesburg', 'tshwane', 'cape_town', 'ethekwini', 'ekurhuleni', 'nelson_mandela_bay', 'buffalo_city', 'mangaung', 'other'],
            array_column($metros, 'code'),
        );

        foreach ($metros as $metro) {
            $this->assertSame(['code', 'name', 'dispute_window_days', 'response_wait_days', 'dispute_channels', 'escalation_steps', 'verified'], array_keys($metro));
            $this->assertSame(30, $metro['dispute_window_days']);
            $this->assertSame(30, $metro['response_wait_days']);
            $this->assertFalse($metro['verified']);
            $this->assertSame('statement', $metro['dispute_channels'][0]['type']);
            $this->assertSame([0, 1, 2, 3], array_column($metro['escalation_steps'], 'level'));
            $this->assertSame(
                ['Billing query', 'Senior revenue official', 'Ward councillor or municipal ombudsman', 'Public Protector'],
                array_column($metro['escalation_steps'], 'name'),
            );
        }

        $this->assertSame('eThekwini Metropolitan Municipality', collect($metros)->firstWhere('code', 'ethekwini')['name']);
    }

    public function test_no_contact_details_are_invented(): void
    {
        $json = $this->getJson('/api/v1/metros')->getContent();

        $this->assertDoesNotMatchRegularExpression('/@|https?:|www\.|\.gov\.za|\.co\.za|\b0\d{2}[\s-]?\d{3}[\s-]?\d{4}\b/', $json);
    }

    public function test_cors_allows_the_configured_frontends_only(): void
    {
        $this->call('OPTIONS', '/api/v1/metros', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');

        $this->call('OPTIONS', '/api/v1/metros', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:8081',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8081');

        $this->call('OPTIONS', '/api/v1/metros', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
