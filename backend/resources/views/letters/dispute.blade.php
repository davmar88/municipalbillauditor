{{-- Plain-text dispute letter. Uses raw echoes: this is text, not HTML. --}}
{!! $date !!}

To: The Billing and Revenue Department
{!! $municipality !!}

Account number: {!! $accountNumber !!}
Property address: {!! $address !!}
@if ($billDate)
Bill date: {!! $billDate !!}
@endif
@if ($periodStart && $periodEnd)
Billing period: {!! $periodStart !!} to {!! $periodEnd !!}
@endif

Dear Sir or Madam

DISPUTE OF MUNICIPAL ACCOUNT {!! $accountNumber !!}

I am writing to formally dispute the following charges on my municipal account{!! $billDate ? ' for the bill dated '.$billDate : '' !!}.

@foreach ($items as $i => $item)
{!! $i + 1 !!}. {!! $item['heading'] !!}
{!! $item['paragraph'] !!}
Amount disputed: {!! $item['amount'] ?? 'to be confirmed once the account has been corrected' !!}

@endforeach
@if ($total)
Total amount disputed: {!! $total !!}{!! $someUnknown ? ', plus any amounts still to be confirmed above' : '' !!}
@if ($overlapping)
Some of the items above concern the same charge, so each charge is counted only once in this total.
@endif
@else
Total amount disputed: to be confirmed once the account has been corrected
@endif

I request that you:
- investigate these charges and correct my account;
@if ($meterServices)
- take an actual reading of my {!! $meterServices !!} {!! $meterPlural ? 'meters' : 'meter' !!} and bill me on actual readings;
@endif
- credit my account with any amount I have been overcharged; and
- acknowledge this dispute in writing and give me a reference number.

Under section 102(2) of the Local Government: Municipal Systems Act 32 of 2000, the municipality should not implement credit control or debt collection measures in respect of the specific amount in dispute while this dispute is being resolved. I will continue to pay the undisputed portion of my account.

Please reply to me in writing at {!! $email !!}.

Yours faithfully

{!! $name !!}
{!! $email !!}

--
This letter was prepared with Municipal Bill Auditor. It is not legal advice.
