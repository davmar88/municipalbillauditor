<?php

namespace App\Services\Replies;

/**
 * What we ask Claude for when it reads a municipality's reply to a dispute,
 * as a JSON schema for structured outputs.
 */
final class ReplySchema
{
    public const SYSTEM = <<<'TEXT'
        You read emails that South African municipalities send in reply to billing disputes, for a service that
        lodges and follows up those disputes on behalf of residents and businesses. Report only what the email
        says. Never invent a reference number, an amount or a reason: use null instead. Write the summary and
        the customer action in plain English for someone who has never dealt with the municipality.
        TEXT;

    public const INSTRUCTIONS = <<<'TEXT'
        Read this reply to the dispute described above and classify it.

        - acknowledgement: the municipality has received or logged the dispute, usually with a reference,
          case, query or ticket number. Copy that number exactly into reference.
        - more_information: they need something from the customer before they can decide, such as a meter
          reading, a photo, proof of payment or an ID copy. Say what in information_requested.
        - upheld: they agree, correct the account or promise a credit or refund. Put any amount in rand in
          credit_amount.
        - rejected: they decline the dispute. Put their reason in reason.
        - auto_reply: an out-of-office message or other automatic reply that says nothing about this dispute.
        - unrelated: anything else that has nothing to do with this dispute.

        summary: two or three sentences on what the reply says, for the customer.
        customer_action: what the customer needs to do, if anything, in one or two sentences; null if nothing.
        TEXT;

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $string = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];

        $properties = [
            'classification' => ['type' => 'string', 'enum' => ['acknowledgement', 'more_information', 'upheld', 'rejected', 'auto_reply', 'unrelated']],
            'reference' => $string + ['description' => 'The reference, case or query number, exactly as written.'],
            'credit_amount' => ['anyOf' => [['type' => 'number'], ['type' => 'null']], 'description' => 'A credit or refund promised, in rand.'],
            'reason' => $string + ['description' => 'Why the dispute was rejected.'],
            'information_requested' => $string + ['description' => 'What the municipality needs from the customer.'],
            'summary' => ['type' => 'string'],
            'customer_action' => $string,
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
