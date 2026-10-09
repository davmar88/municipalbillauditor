<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Outage;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('bills');
        $this->user = $this->signIn(User::factory()->create(['password' => 'secret-password']));
        $this->property = Property::factory()->for($this->user)->create(['account_number' => '5501234567', 'property_type' => 'residential']);
        $bills = $this->waterHistory($this->property, [10, 12, 11], ['consumption' => 20, 'reading_type' => 'estimated']);
        $last = end($bills);
        $this->postJson("/api/v1/bills/{$last->id}/disputes", ['finding_ids' => $last->findings()->pluck('id')->all()])->assertCreated();
        Outage::factory()->for($this->property)->create();
        $this->post("/api/v1/properties/{$this->property->id}/bills", ['file' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_export_returns_everything_as_a_download(): void
    {
        $response = $this->get('/api/v1/me/export')->assertOk();

        $response->assertHeader('Content-Disposition', 'attachment; filename="my-data.json"');
        $data = $response->json();
        $this->assertSame(['exported_at', 'user', 'properties', 'disputes'], array_keys($data));
        $this->assertSame($this->user->email, $data['user']['email']);

        $property = $data['properties'][0];
        $this->assertSame('5501234567', $property['account_number']);
        $this->assertCount(5, $property['bills']);
        $this->assertCount(1, $property['outages']);
        $this->assertArrayHasKey('line_items', $property['bills'][0]);
        $this->assertArrayHasKey('findings', $property['bills'][0]);
        $this->assertSame('service', array_keys($property['outages'][0])[2]);

        $this->assertCount(1, $data['disputes']);
        $this->assertArrayHasKey('letter_body', $data['disputes'][0]);
        $this->assertArrayHasKey('events', $data['disputes'][0]);
    }

    public function test_delete_requires_the_password(): void
    {
        $this->deleteJson('/api/v1/me', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->deleteJson('/api/v1/me')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_password_guesses_on_delete_are_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->deleteJson('/api/v1/me', ['password' => "guess-{$attempt}"])->assertUnprocessable();
        }

        // Even the right password is refused until the minute is up.
        $this->deleteJson('/api/v1/me', ['password' => 'secret-password'])->assertStatus(429);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);

        // A few minutes of guessing later, the hourly limit takes over.
        foreach (range(1, 3) as $minute) {
            $this->travel(61)->seconds();
            foreach (range(1, 5) as $attempt) {
                $this->deleteJson('/api/v1/me', ['password' => "guess-{$minute}-{$attempt}"])->assertUnprocessable();
            }
        }
        $this->travel(61)->seconds();
        $this->deleteJson('/api/v1/me', ['password' => 'secret-password'])->assertStatus(429);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);

        $this->travel(1)->hours();
        $this->deleteJson('/api/v1/me', ['password' => 'secret-password'])->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    public function test_delete_removes_every_row_file_and_token(): void
    {
        $other = User::factory()->create();
        $otherProperty = Property::factory()->for($other)->create();
        $this->makeBill($otherProperty, [['service' => 'rates', 'amount_cents' => 100]]);
        $token = $this->user->createToken('phone')->plainTextToken;
        $paths = Bill::whereNotNull('file_path')->pluck('file_path');
        $this->assertCount(1, $paths);

        $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'secret-password'])->assertNoContent();

        Storage::disk('bills')->assertMissing($paths[0]);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->user->id]);
        $this->assertDatabaseCount('disputes', 0);
        $this->assertDatabaseCount('dispute_events', 0);
        $this->assertDatabaseCount('outages', 0);
        $this->assertDatabaseCount('findings', 0);
        // Only the other user's data is left.
        $this->assertSame([$otherProperty->id], Property::pluck('id')->all());
        $this->assertSame(1, Bill::count());
        $this->assertDatabaseCount('line_items', 1);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
