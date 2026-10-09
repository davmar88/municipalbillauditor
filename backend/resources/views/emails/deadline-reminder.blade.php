Hi {!! $name !!}

@if (count($items) === 1)
You have a deadline coming up in the next few days:
@else
You have deadlines coming up in the next few days:
@endif

@foreach ($items as $item)
- {!! $item['label'] !!} ({!! $item['property'] !!}): due {!! $item['due'] !!}
@endforeach

Open Municipal Bill Auditor to see the details and your next steps. Acting before the deadline keeps your options open with the municipality.

Municipal Bill Auditor
This is a reminder, not legal advice.
