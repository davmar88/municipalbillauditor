<?php

namespace App\Extraction;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Throwable;

/**
 * Extracts bill fields with Claude (vision + PDF input, structured JSON
 * output). Only used when ANTHROPIC_API_KEY is set and the user opted in to
 * AI extraction, because the bill is sent to a processor outside South
 * Africa (POPIA s72).
 */
class ClaudeBillExtractor implements BillExtractor
{
    /** Image types the Messages API accepts as base64 image blocks. */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const PDF_TYPE = 'application/pdf';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens = 16000,
        private readonly ?Client $client = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->client !== null || ($this->apiKey !== null && trim($this->apiKey) !== '');
    }

    public function extract(string $contents, string $mimeType): ExtractedBill
    {
        if (! $this->isAvailable()) {
            throw BillExtractionFailed::because('not_configured');
        }

        $document = $this->documentBlock($contents, $mimeType);

        try {
            $message = $this->client()->messages->create(
                model: $this->model,
                maxTokens: $this->maxTokens,
                system: $this->systemPrompt(),
                messages: [[
                    'role' => 'user',
                    'content' => [
                        $document,
                        ['type' => 'text', 'text' => $this->instructions()],
                    ],
                ]],
                outputConfig: [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => self::schema(),
                    ],
                ],
            );
        } catch (APIException $e) {
            // Don't pass on API error bodies: report only the class.
            throw BillExtractionFailed::because('api_error:'.class_basename($e));
        } catch (Throwable $e) {
            throw BillExtractionFailed::because('request_failed:'.class_basename($e));
        }

        if ($message->stopReason === 'refusal') {
            throw BillExtractionFailed::because('refusal');
        }
        if ($message->stopReason === 'max_tokens') {
            throw BillExtractionFailed::because('max_tokens');
        }

        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
                break;
            }
        }

        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            throw BillExtractionFailed::because('invalid_output');
        }

        return ExtractedBill::fromArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentBlock(string $contents, string $mimeType): array
    {
        if ($mimeType === self::PDF_TYPE) {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'mediaType' => self::PDF_TYPE,
                    'data' => base64_encode($contents),
                ],
            ];
        }

        if (in_array($mimeType, self::IMAGE_TYPES, true)) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'mediaType' => $mimeType,
                    'data' => base64_encode($contents),
                ],
            ];
        }

        // e.g. HEIC photos: not accepted by the API as image input.
        throw BillExtractionFailed::because('unsupported_file_type');
    }

    private function client(): Client
    {
        return $this->client ?? new Client(apiKey: $this->apiKey);
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You read South African municipal bills (statements of account) and return their contents as structured data. Copy what the bill says; never guess or invent values. When a value isn't shown or you can't read it, use null.
TXT;
    }

    private function instructions(): string
    {
        return <<<'TXT'
Extract this municipal bill.

- bill_date: the statement/invoice date. period_start / period_end: the billing or meter-reading period. due_date: the payment due date. All dates as YYYY-MM-DD, or null.
- total_cents: the total of CURRENT charges for this period in cents (R1 523.40 = 152340). Exclude the opening balance, arrears, payments received and interest on arrears. Null if not shown.
- line_items: one item per current charge line (water, electricity, sewerage, refuse, property rates, other).
  - service: water, electricity, sewerage, refuse, rates or other (use other for VAT shown as its own line, levies and sundry charges).
  - description: the line's text as printed.
  - tariff_category: the tariff or customer class printed for the line (for example "Residential", "Business", "Domestic bulk"), or null.
  - reading_type: "estimated" when the bill marks the reading as estimated (for example E, Est or Estimated), "actual" when it is marked actual (for example A or Actual), otherwise "unknown".
  - previous_reading / current_reading / consumption: the meter readings and usage as numbers, or null. Water in kilolitres (1 m³ = 1 kl), electricity in kWh.
  - unit: "kl" for water usage, "kwh" for electricity usage, otherwise null.
  - amount_cents: the line amount in cents as a whole number (0 or more).
TXT;
    }

    /**
     * JSON schema for the structured output.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $nullable = fn (array $type) => ['anyOf' => [$type, ['type' => 'null']]];
        $date = $nullable(['type' => 'string', 'description' => 'YYYY-MM-DD']);

        $lineItem = [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string', 'enum' => ['water', 'electricity', 'sewerage', 'refuse', 'rates', 'other']],
                'description' => $nullable(['type' => 'string']),
                'tariff_category' => $nullable(['type' => 'string']),
                'reading_type' => ['type' => 'string', 'enum' => ['actual', 'estimated', 'unknown']],
                'previous_reading' => $nullable(['type' => 'number']),
                'current_reading' => $nullable(['type' => 'number']),
                'consumption' => $nullable(['type' => 'number']),
                'unit' => $nullable(['type' => 'string', 'enum' => ['kl', 'kwh']]),
                'amount_cents' => ['type' => 'integer'],
            ],
            'required' => ['service', 'description', 'tariff_category', 'reading_type', 'previous_reading', 'current_reading', 'consumption', 'unit', 'amount_cents'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'bill_date' => $date,
                'period_start' => $date,
                'period_end' => $date,
                'due_date' => $date,
                'total_cents' => $nullable(['type' => 'integer']),
                'line_items' => ['type' => 'array', 'items' => $lineItem],
            ],
            'required' => ['bill_date', 'period_start', 'period_end', 'due_date', 'total_cents', 'line_items'],
            'additionalProperties' => false,
        ];
    }
}
