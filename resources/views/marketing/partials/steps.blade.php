{{-- Five steps from the photo to the credit. --}}
<ol class="grid grid-cols-[repeat(auto-fit,minmax(min(200px,100%),1fr))] gap-6">
    @foreach ($steps as $step)
        <li class="flex flex-col gap-2.5">
            <span aria-hidden="true" class="bg-brand h-1 rounded-full"></span>
            <span class="bg-brand mt-1.5 flex size-9 items-center justify-center rounded-full font-mono text-sm font-bold text-ground">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
            <h3 class="text-xl leading-tight font-bold">{{ $step['title'] }}</h3>
            <p class="text-base text-ink-2">{{ $step['text'] }}</p>
        </li>
    @endforeach
</ol>
