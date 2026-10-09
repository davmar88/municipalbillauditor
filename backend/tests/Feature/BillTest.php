<?php

namespace Tests\Feature;

use App\Extraction\BillExtractionFailed;
use App\Extraction\ExtractedBill;
use App\Models\Bill;
use App\Models\LineItem;
use App\Models\Property;
use App\Models\User;
use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BillTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('bills');
        $this->user = $this->signIn();
        $this->property = Property::factory()->for($this->user)->create(['property_type' => 'residential', 'metro' => 'ethekwini']);
    }

    private function waterLine(array $overrides = []): array
    {
        return [
            'service' => 'water',
            'description' => 'Water consumption',
            'tariff_category' => 'Residential',
            'reading_type' => 'actual',
            'previous_reading' => 1203,
            'current_reading' => 1241,
            'unit' => 'kl',
            'amount_cents' => 152340,
            ...$overrides,
        ];
    }

    private function aiResult(): ExtractedBill
    {
        return new ExtractedBill(
            billDate: '2026-09-25',
            periodStart: '2026-08-20',
            periodEnd: '2026-09-19',
            dueDate: '2026-10-15',
            totalCents: 200000,
            lineItems: [
                ['service' => 'water', 'description' => 'Water', 'tariff_category' => 'Business', 'reading_type' => 'estimated', 'previous_reading' => null, 'current_reading' => null, 'consumption' => 20.0, 'unit' => 'kl', 'amount_cents' => 60000],
                ['service' => 'rates', 'description' => 'Rates', 'tariff_category' => null, 'reading_type' => 'unknown', 'previous_reading' => null, 'current_reading' => null, 'consumption' => null, 'unit' => null, 'amount_cents' => 140000],
                ['service' => 'gas', 'amount_cents' => -5],
            ],
        );
    }

    public function test_create_with_line_items_audits_immediately(): void
    {
        $response = $this->postJson("/api/v1/properties/{$this->property->id}/bills", [
            'bill_date' => '2026-09-25',
            'period_start' => '2026-08-20',
            'period_end' => '2026-09-19',
            'due_date' => '2026-10-15',
            'total_cents' => 152340,
            'line_items' => [$this->waterLine()],
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame(
            ['id', 'property_id', 'bill_date', 'period_start', 'period_end', 'due_date', 'total_cents', 'status', 'extraction_source', 'has_file', 'file_mime', 'dispute_deadline', 'findings_summary', 'line_items', 'findings', 'created_at'],
            array_keys($data),
        );
        $this->assertSame('audited', $data['status']);
        $this->assertSame('manual', $data['extraction_source']);
        $this->assertFalse($data['has_file']);
        $this->assertNull($data['file_mime']);
        $this->assertSame('2026-10-25', $data['dispute_deadline']);
        $this->assertSame(['open_count' => 0, 'high_count' => 0, 'potential_overcharge_cents' => 0], $data['findings_summary']);
        $this->assertSame([], $data['findings']);
        $this->assertSame([
            'id' => $data['line_items'][0]['id'],
            'service' => 'water',
            'description' => 'Water consumption',
            'tariff_category' => 'Residential',
            'reading_type' => 'actual',
            'previous_reading' => 1203.0,
            'current_reading' => 1241.0,
            // Computed by the server from the readings.
            'consumption' => 38.0,
            'unit' => 'kl',
            'amount_cents' => 152340,
        ], $data['line_items'][0]);
        // Decimals keep their decimal point in the JSON itself.
        $this->assertStringContainsString('"consumption":38.0', $response->getContent());
    }

    public function test_line_item_defaults(): void
    {
        $response = $this->postJson("/api/v1/properties/{$this->property->id}/bills", [
            'line_items' => [['service' => 'refuse', 'amount_cents' => 25000]],
        ])->assertCreated();

        $this->assertSame([
            'id' => $response->json('data.line_items.0.id'),
            'service' => 'refuse',
            'description' => '',
            'tariff_category' => null,
            'reading_type' => 'unknown',
            'previous_reading' => null,
            'current_reading' => null,
            'consumption' => null,
            'unit' => null,
            'amount_cents' => 25000,
        ], $response->json('data.line_items.0'));
        $this->assertNull($response->json('data.bill_date'));
        $this->assertNull($response->json('data.dispute_deadline'));
        $this->assertNull($response->json('data.total_cents'));
    }

    public function test_line_item_description_is_always_a_string(): void
    {
        $bill = $this->postJson("/api/v1/properties/{$this->property->id}/bills", [
            'line_items' => [
                ['service' => 'rates', 'amount_cents' => 50000],
                ['service' => 'refuse', 'description' => null, 'amount_cents' => 25000],
                ['service' => 'sewerage', 'description' => '   ', 'amount_cents' => 30000],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(['', '', ''], array_column($bill['line_items'], 'description'));
        $this->assertSame(['', '', ''], LineItem::where('bill_id', $bill['id'])->orderBy('id')->pluck('description')->all());

        // Rows saved as null before this rule existed still come back as "".
        LineItem::where('bill_id', $bill['id'])->update(['description' => null]);
        $this->getJson("/api/v1/bills/{$bill['id']}")
            ->assertOk()
            ->assertJsonPath('data.line_items.0.description', '');
    }

    public function test_multipart_upload_with_line_items_as_a_json_string(): void
    {
        $response = $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('my-bill-5501234567.jpg', 800, 1200),
            'bill_date' => '2026-09-25',
            'total_cents' => '152340',
            'line_items' => json_encode([$this->waterLine()]),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'audited')
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.file_mime', 'image/jpeg')
            ->assertJsonPath('data.total_cents', 152340)
            ->assertJsonPath('data.line_items.0.consumption', 38.0);

        $bill = Bill::find($response->json('data.id'));
        Storage::disk('bills')->assertExists($bill->file_path);
        // Random file name: nothing from the original name or the user.
        $this->assertStringNotContainsString('5501234567', $bill->file_path);
        $this->assertStringNotContainsString('my-bill', $bill->file_path);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.(jpg|jpeg)$/', $bill->file_path);
    }

    public function test_file_only_without_ai_needs_review(): void
    {
        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->create('bill.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.extraction_source', 'manual')
            ->assertJsonPath('data.line_items', [])
            ->assertJsonPath('data.findings', []);
    }

    public function test_file_only_with_consent_but_no_ai_configured_needs_review(): void
    {
        $this->user->update(['ai_extraction_consent' => true]);

        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'needs_review');

        $this->assertSame([], $this->extractor->calls);
    }

    public function test_ai_is_never_used_without_the_users_consent(): void
    {
        $this->extractor->willReturn($this->aiResult());

        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'needs_review');

        $this->assertSame([], $this->extractor->calls);
    }

    public function test_ai_extraction_fills_the_bill_and_audits_it(): void
    {
        $this->user->update(['ai_extraction_consent' => true]);
        $this->extractor->willReturn($this->aiResult());

        $response = $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.jpg'),
            // What the user typed wins over what the AI read.
            'due_date' => '2026-10-20',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'audited')
            ->assertJsonPath('data.extraction_source', 'ai')
            ->assertJsonPath('data.bill_date', '2026-09-25')
            ->assertJsonPath('data.period_start', '2026-08-20')
            ->assertJsonPath('data.due_date', '2026-10-20')
            ->assertJsonPath('data.total_cents', 200000)
            ->assertJsonCount(2, 'data.line_items')
            ->assertJsonPath('data.findings.0.rule', 'tariff_mismatch');

        $this->assertSame([['mime' => 'image/jpeg', 'bytes' => $this->extractor->calls[0]['bytes']]], $this->extractor->calls);
        $this->assertContains('estimated_reading', array_column($response->json('data.findings'), 'rule'));
    }

    public function test_ai_extraction_failure_marks_the_bill(): void
    {
        $this->user->update(['ai_extraction_consent' => true]);
        $this->extractor->willFail(BillExtractionFailed::because('refusal'));

        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'extraction_failed')
            ->assertJsonPath('data.line_items', []);

        $this->extractor->willFail(new RuntimeException('network down'));
        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'extraction_failed');
    }

    public function test_extraction_failures_log_only_the_bill_id_and_a_reason(): void
    {
        Log::spy();
        $this->user->update(['ai_extraction_consent' => true]);
        $this->extractor->willFail(new RuntimeException('Account 5501234567 owes R1 523.40'));

        $id = $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.jpg'),
        ], ['Accept' => 'application/json'])->json('data.id');

        Log::shouldHaveReceived('warning')->once()->with('Bill extraction failed.', ['bill_id' => $id, 'reason' => 'RuntimeException']);
    }

    public function test_heic_photos_are_accepted(): void
    {
        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->create('IMG_0001.heic', 300, 'image/heic'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.file_mime', 'image/heic')
            ->assertJsonPath('data.status', 'needs_review');
    }

    public function test_ai_extraction_with_no_usable_line_items_fails(): void
    {
        $this->user->update(['ai_extraction_consent' => true]);
        $this->extractor->willReturn(new ExtractedBill(null, null, null, null, null, [['service' => 'water']]));

        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'extraction_failed');
    }

    public function test_a_failed_extraction_can_be_completed_by_hand(): void
    {
        $this->user->update(['ai_extraction_consent' => true]);
        $this->extractor->willFail();

        $id = $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => UploadedFile::fake()->image('bill.jpg'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->putJson("/api/v1/bills/{$id}", ['bill_date' => '2026-09-25', 'line_items' => [$this->waterLine()]])
            ->assertOk()
            ->assertJsonPath('data.status', 'audited')
            ->assertJsonPath('data.extraction_source', 'manual')
            ->assertJsonPath('data.has_file', true);
    }

    public function test_file_or_line_items_are_required_and_validated(): void
    {
        $url = "/api/v1/properties/{$this->property->id}/bills";

        $this->postJson($url, ['bill_date' => '2026-09-25'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'line_items']);

        $this->post($url, ['line_items' => '{not json'], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('line_items');

        $this->post($url, ['file' => UploadedFile::fake()->create('bill.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->post($url, ['file' => UploadedFile::fake()->create('bill.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file', ['The file is too big. The limit is 10 MB.']);

        $this->postJson($url, ['line_items' => [['service' => 'gas', 'amount_cents' => -1, 'reading_type' => 'guess', 'unit' => 'm3']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['line_items.0.service', 'line_items.0.amount_cents', 'line_items.0.reading_type', 'line_items.0.unit']);

        $this->postJson($url, ['line_items' => [['service' => 'water']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('line_items.0.amount_cents');

        $this->postJson($url, ['period_start' => '2026-09-19', 'period_end' => '2026-08-20', 'bill_date' => '25/09/2026', 'line_items' => [$this->waterLine()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_end', 'bill_date']);

        $this->assertDatabaseCount('bills', 0);
    }

    /**
     * A file PHP itself turned away (bigger than upload_max_filesize) arrives
     * with an error code and no path. The user should hear that the file is
     * too big, not that line items are missing.
     */
    public function test_a_file_php_rejected_as_too_big_gets_a_friendly_file_error_only(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $error) {
            $response = $this->post("/api/v1/properties/{$this->property->id}/bills", [
                'file' => new UploadedFile('', 'IMG_2041.jpg', 'image/jpeg', $error, true),
            ], ['Accept' => 'application/json']);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'The file is too big. The limit is 10 MB.')
                ->assertJsonPath('errors', ['file' => ['The file is too big. The limit is 10 MB.']]);
        }

        $this->assertDatabaseCount('bills', 0);
    }

    public function test_a_file_that_did_not_upload_asks_the_user_to_try_again(): void
    {
        $this->post("/api/v1/properties/{$this->property->id}/bills", [
            'file' => new UploadedFile('', 'IMG_2041.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true),
            'line_items' => json_encode([$this->waterLine()]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['file' => ["Your file didn't upload. Please try again."]]);

        $this->assertDatabaseCount('bills', 0);
    }

    /**
     * A request over PHP's post_max_size arrives with an empty body; Laravel
     * raises PostTooLargeException. For an upload this is a file error.
     */
    public function test_an_upload_over_the_php_request_limit_is_a_friendly_file_error(): void
    {
        $tooBig = (string) (UploadLimits::iniBytes((string) ini_get('post_max_size')) + 1);

        $this->call('POST', "/api/v1/properties/{$this->property->id}/bills", server: [
            'CONTENT_LENGTH' => $tooBig,
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----bill',
            'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The file is too big. The limit is 10 MB.',
                'errors' => ['file' => ['The file is too big. The limit is 10 MB.']],
            ]);

        // A huge JSON body isn't a file, so it stays a plain 413.
        $this->call('POST', "/api/v1/properties/{$this->property->id}/bills", server: [
            'CONTENT_LENGTH' => $tooBig,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertStatus(413)
            ->assertExactJson(['message' => 'That request is too big to process.']);
    }

    public function test_list_uses_the_collection_shape_newest_bill_date_first_nulls_last(): void
    {
        $line = [$this->waterLine()];
        $undated = $this->makeBill($this->property, $line);
        $old = $this->makeBill($this->property, $line, ['bill_date' => '2026-07-25']);
        $new = $this->makeBill($this->property, $line, ['bill_date' => '2026-09-25']);

        $response = $this->getJson("/api/v1/properties/{$this->property->id}/bills")->assertOk();

        $this->assertSame([$new->id, $old->id, $undated->id], array_column($response->json('data'), 'id'));
        $this->assertSame(
            ['id', 'property_id', 'bill_date', 'period_start', 'period_end', 'due_date', 'total_cents', 'status', 'extraction_source', 'has_file', 'file_mime', 'dispute_deadline', 'findings_summary', 'created_at'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_show_orders_findings_by_severity_then_id(): void
    {
        // Estimated (medium), business tariff (high) and total mismatch (low).
        $bills = $this->waterHistory($this->property, [10, 12, 11], [
            'consumption' => 20,
            'reading_type' => 'estimated',
            'tariff_category' => 'Business',
        ]);
        $bill = end($bills);
        $bill->update(['total_cents' => 99999999]);
        $this->postJson("/api/v1/bills/{$bill->id}/audit")->assertOk();

        $response = $this->getJson("/api/v1/bills/{$bill->id}")->assertOk();

        $this->assertSame(['high', 'medium', 'low'], array_column($response->json('data.findings'), 'severity'));
        $this->assertSame(['tariff_mismatch', 'estimated_reading', 'arithmetic_mismatch'], array_column($response->json('data.findings'), 'rule'));
        $this->assertSame(['open_count' => 2, 'high_count' => 1, 'potential_overcharge_cents' => 27000], $response->json('data.findings_summary'));
        $finding = $response->json('data.findings.1');
        $this->assertSame(
            ['id', 'bill_id', 'rule', 'severity', 'confidence', 'title', 'explanation', 'estimated_overcharge_cents', 'evidence', 'status', 'created_at'],
            array_keys($finding),
        );
        $this->assertSame(0.9, $finding['confidence']);
        $this->assertSame(27000, $finding['estimated_overcharge_cents']);
        $this->assertSame(['service' => 'water', 'consumption' => 20.0, 'median_actual_consumption' => 11.0, 'actual_bills_compared' => 3], $finding['evidence']);
        $this->assertStringContainsString('"median_actual_consumption":11.0', $response->getContent());
    }

    public function test_the_original_file_is_streamed_to_its_owner(): void
    {
        $file = UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\n%fake bill\n");
        $id = $this->post("/api/v1/properties/{$this->property->id}/bills", ['file' => $file], ['Accept' => 'application/json'])->json('data.id');

        $response = $this->get("/api/v1/bills/{$id}/file");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame("%PDF-1.4\n%fake bill\n", $response->streamedContent());

        $manual = $this->makeBill($this->property, [$this->waterLine()]);
        $this->getJson("/api/v1/bills/{$manual->id}/file")->assertNotFound();
    }

    public function test_put_replaces_line_items_reaudits_and_keeps_the_extraction_source(): void
    {
        $this->user->update(['ai_extraction_consent' => true]);
        $this->extractor->willReturn($this->aiResult());
        $id = $this->post("/api/v1/properties/{$this->property->id}/bills", ['file' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])
            ->json('data.id');
        $this->assertSame(2, Bill::find($id)->findings()->count());

        $response = $this->putJson("/api/v1/bills/{$id}", [
            'bill_date' => null,
            'total_cents' => 152340,
            'line_items' => [$this->waterLine()],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.extraction_source', 'ai')
            ->assertJsonPath('data.bill_date', null)
            ->assertJsonPath('data.dispute_deadline', null)
            ->assertJsonPath('data.period_start', '2026-08-20')
            ->assertJsonPath('data.total_cents', 152340)
            ->assertJsonCount(1, 'data.line_items')
            ->assertJsonPath('data.findings', []);
        $this->assertDatabaseCount('line_items', 1);

        $this->putJson("/api/v1/bills/{$id}", ['line_items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('line_items');
    }

    public function test_adding_an_earlier_bill_reaudits_later_bills(): void
    {
        $line = fn (float $kl, string $start, string $end) => [
            'period_start' => $start,
            'period_end' => $end,
            'bill_date' => $end,
            'line_items' => [['service' => 'water', 'reading_type' => 'actual', 'consumption' => $kl, 'unit' => 'kl', 'amount_cents' => (int) ($kl * 3000)]],
        ];
        $url = "/api/v1/properties/{$this->property->id}/bills";

        $latest = $this->postJson($url, $line(40, '2026-04-01', '2026-04-30'))->json('data.id');
        $this->postJson($url, $line(10, '2026-03-01', '2026-03-31'));
        $this->postJson($url, $line(10, '2026-02-01', '2026-02-28'));
        $this->assertSame([], $this->getJson("/api/v1/bills/{$latest}")->json('data.findings'));

        $this->postJson($url, $line(10, '2026-01-01', '2026-01-31'));

        $this->assertSame('consumption_spike', $this->getJson("/api/v1/bills/{$latest}")->json('data.findings.0.rule'));
    }

    public function test_delete_removes_the_file_line_items_findings_and_disputes(): void
    {
        $bills = $this->waterHistory($this->property, [10, 12, 11], ['consumption' => 20, 'reading_type' => 'estimated']);
        $bill = end($bills);
        $this->postJson("/api/v1/bills/{$bill->id}/disputes", ['finding_ids' => $bill->findings()->pluck('id')->all()])->assertCreated();
        $withFile = $this->post("/api/v1/properties/{$this->property->id}/bills", ['file' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->json('data.id');
        $path = Bill::find($withFile)->file_path;

        $this->deleteJson("/api/v1/bills/{$bill->id}")->assertNoContent();
        $this->deleteJson("/api/v1/bills/{$withFile}")->assertNoContent();

        Storage::disk('bills')->assertMissing($path);
        $this->assertDatabaseMissing('bills', ['id' => $bill->id]);
        $this->assertDatabaseMissing('line_items', ['bill_id' => $bill->id]);
        $this->assertDatabaseCount('findings', 0);
        $this->assertDatabaseCount('disputes', 0);
        $this->assertDatabaseCount('dispute_events', 0);
        $this->getJson("/api/v1/bills/{$bill->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
}
