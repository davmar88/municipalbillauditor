{{-- The four errors, each with a marked-up snippet of a bill. --}}
<div class="grid grid-cols-[repeat(auto-fit,minmax(min(500px,100%),1fr))] gap-6">
    @foreach ($checks as $check)
        <article class="flex flex-col gap-4 rounded-[10px] border border-rule-soft bg-well p-7">
            <div class="flex items-baseline gap-3">
                <span class="font-mono text-sm font-semibold text-flag-ink">{{ $check['number'] }}</span>
                <h3 class="text-2xl leading-tight font-bold">{{ $check['title'] }}</h3>
            </div>
            <div aria-hidden="true" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded border border-rule bg-paper px-4 py-3.5 font-mono text-sm">
                @if ($check['number'] === '04')
                    <div class="grid h-12 flex-1 grid-cols-6 items-end gap-1.5">
                        @foreach ([19, 20, 18, 21, 20] as $height)
                            <div class="rounded-t-[4px] bg-chart-muted" style="height: {{ $height }}px"></div>
                        @endforeach
                        <div class="h-12 rounded-t-[4px] bg-flag"></div>
                    </div>
                    <span class="font-semibold text-flag-ink">{{ $check['snippet']['marked'] }}</span>
                @else
                    @if ($check['snippet']['left'] !== '')
                        <span>{{ $check['snippet']['left'] }}</span>
                    @endif
                    <span class="relative mx-2 inline-block px-1 font-semibold text-flag-ink">
                        {{ $check['snippet']['marked'] }}
                        <span class="absolute -inset-x-2 -inset-y-1.5 -rotate-3 rounded-[50%] border-2 border-flag"></span>
                    </span>
                    @if ($check['snippet']['right'] !== '')
                        <span @class(['font-semibold text-action-ink' => str_starts_with($check['snippet']['right'], 'should'), 'text-muted' => ! str_starts_with($check['snippet']['right'], 'should')])>{{ $check['snippet']['right'] }}</span>
                    @endif
                @endif
            </div>
            <p class="text-ink-2">{{ $check['problem'] }}</p>
            <p class="border-t border-rule pt-3.5"><b>What we do:</b> {{ preg_replace('/^We /', '', $check['fix']) }}</p>
        </article>
    @endforeach
</div>
