<section aria-labelledby="cta-h" class="bg-ink py-[clamp(64px,8vw,96px)] text-white">
    <div class="mx-auto flex max-w-[1200px] flex-col items-start gap-5 px-[clamp(16px,4vw,40px)]">
        <h2 id="cta-h" class="max-w-[820px] text-[clamp(32px,4.4vw,56px)] leading-[1.05] font-extrabold tracking-tight text-balance">Check your bill before the dispute window closes.</h2>
        <p class="max-w-[620px] text-[19px] text-night-text">It's free. You'll see what's wrong, what it's worth and your deadline.</p>
        <a href="{{ $checkUrl }}" class="mt-2 flex h-14 items-center gap-2.5 rounded-lg bg-white px-6 text-lg font-bold text-ink no-underline hover:bg-ground hover:text-ink">
            @include('marketing.partials.camera')
            Check my bill free
        </a>
    </div>
</section>
