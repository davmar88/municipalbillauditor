<?php

namespace Tests\Feature;

use App\Mail\DeadlineReminder;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeadlineReminderTest extends TestCase
{
    use RefreshDatabase;

    private function billDueIn(User $user, int $days): void
    {
        $property = Property::factory()->for($user)->create(['nickname' => "Due in $days", 'account_number' => '5501234567', 'property_type' => 'residential']);
        // A business tariff (high finding) on a bill whose lodge deadline is today + $days.
        $this->makeBill($property, [['service' => 'water', 'tariff_category' => 'Business', 'amount_cents' => 50000]], [
            'bill_date' => CarbonImmutable::today()->addDays($days - 30)->toDateString(),
        ]);
    }

    public function test_emails_users_about_deadlines_due_within_five_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09T05:00:00Z'));
        Mail::fake();

        $soon = User::factory()->create(['email' => 'soon@example.com']);
        $this->billDueIn($soon, 3);
        $this->billDueIn($soon, 5);
        $later = User::factory()->create(['email' => 'later@example.com']);
        $this->billDueIn($later, 6);
        $overdue = User::factory()->create(['email' => 'overdue@example.com']);
        $this->billDueIn($overdue, -1);

        $this->artisan('deadlines:remind')->expectsOutput('Sent 1 reminder email(s).')->assertSuccessful();

        Mail::assertSent(DeadlineReminder::class, 1);
        Mail::assertSent(DeadlineReminder::class, function (DeadlineReminder $mail) use ($soon) {
            $text = $mail->render();

            return $mail->hasTo($soon->email)
                && count($mail->deadlines) === 2
                && str_contains($text, 'Lodge a dispute for the')
                && str_contains($text, 'due 12 Oct 2026')
                && str_contains($text, 'due 14 Oct 2026')
                && ! str_contains($text, '5501234567');
        });
    }

    public function test_the_reminder_runs_daily_from_the_scheduler(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'deadlines:remind'));

        $this->assertCount(1, $events);
        $this->assertSame('0 7 * * *', $events->first()->expression);
    }
}
