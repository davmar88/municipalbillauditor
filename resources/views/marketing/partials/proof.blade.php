{{-- Only figures with a source show in production; see SiteContent::proof(). --}}
@if (count($proof) > 0)
    <section aria-labelledby="proof-h" class="border-y border-rule bg-night py-[clamp(56px,7vw,80px)] text-white">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-10 px-[clamp(16px,4vw,40px)]">
            <h2 id="proof-h" class="max-w-[760px] text-[clamp(26px,3vw,36px)] leading-[1.15] font-bold tracking-tight">{{ $heading ?? 'Billing errors are common, and they can be fixed.' }}</h2>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(280px,100%),1fr))] gap-8">
                @foreach ($proof as $item)
                    <div class="flex flex-col gap-2 border-t border-rule pt-5">
                        <span class="font-mono text-[clamp(36px,4vw,48px)] leading-none font-semibold tracking-tighter">{{ $item['figure'] }}</span>
                        <p class="text-base text-night-text">
                            {{ $item['text'] }}
                            @if ($item['source'])
                                <a href="{{ $item['source'] }}" class="text-action-light hover:text-white" rel="noopener">Source</a>
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
