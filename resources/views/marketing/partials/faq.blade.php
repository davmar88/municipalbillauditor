{{-- Answers sit in <details>, so the text is in the HTML even when closed. --}}
<div class="flex min-w-0 flex-[2_1_560px] flex-col border-t border-rule">
    @foreach ($faq as $item)
        <details class="group border-b border-rule" @if ($loop->first) open @endif>
            <summary class="flex min-h-16 cursor-pointer items-center justify-between gap-4 py-3 text-[19px] leading-snug font-bold">
                <h3>{{ $item['question'] }}</h3>
                <svg class="size-[22px] flex-none transition-transform group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </summary>
            <p class="pr-10 pb-5 text-ink-2">{{ $item['answer'] }}</p>
        </details>
    @endforeach
</div>
