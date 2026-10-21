<x-mail::message>
# While you were away, {{ $name }}

@foreach ($news as $line)
- {{ $line }}
@endforeach

<x-mail::button :url="route('play')">
Back to the farm
</x-mail::button>

See you in the valley,<br>
The Animal Farm Dev Team

<small>Don't want these? [Stop the news emails]({{ $stop }}), or turn them off on your Me page.</small>
</x-mail::message>
