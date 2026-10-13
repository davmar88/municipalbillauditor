<section aria-labelledby="cta-h" class="relative isolate overflow-hidden border-t border-rule bg-night py-[clamp(64px,8vw,96px)] text-white">
    <div aria-hidden="true" class="bg-glow-low absolute inset-0 -z-10"></div>
    <div class="mx-auto flex max-w-[1200px] flex-col items-start gap-5 px-[clamp(16px,4vw,40px)]">
        <h2 id="cta-h" class="max-w-[820px] text-[clamp(32px,4.4vw,56px)] leading-[1.05] font-extrabold tracking-tight text-balance">Check your bill <span class="text-gradient">before the dispute window closes.</span></h2>
        <p class="max-w-[620px] text-[19px] text-night-text">{{ $sitePrices['welcome'] ? "Your first {$sitePrices['welcome']} of credit is on us." : "Prepaid: {$sitePrices['billCheck']} a bill, from credit you top up from {$sitePrices['smallestTopUp']}." }} You'll see what's wrong, what it's worth and your deadline.</p>
        <a href="{{ $checkUrl }}" class="glow mt-2 flex h-14 items-center gap-2.5 rounded-lg bg-white px-6 text-lg font-bold text-ground no-underline hover:bg-ink-2 hover:text-ground">
            @include('marketing.partials.camera')
            {{ $checkLabel }}
        </a>
    </div>
</section>
