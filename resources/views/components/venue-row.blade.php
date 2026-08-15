@props(['location'])

<div class="flex items-start gap-4 py-4">
    @if ($location->logo_url)
        {{-- Logos are arbitrary aspect ratios, so contain rather than crop. --}}
        <img src="{{ $location->logo_url }}" alt="{{ $location->name }}"
             width="56" height="56" loading="lazy" decoding="async"
             class="h-14 w-14 shrink-0 rounded border border-rule bg-card object-contain p-1">
    @else
        <span aria-hidden="true"
              class="flex h-14 w-14 shrink-0 items-center justify-center rounded bg-olive-soft font-display text-xl font-bold text-olive">
            {{ mb_strtoupper(mb_substr($location->name, 0, 1)) }}
        </span>
    @endif

    <div class="min-w-0 flex-1">
        <h3 class="font-display text-lg font-medium leading-tight">
            <a href="{{ url($location->path) }}" class="hover:text-brick hover:underline">
                {{ $location->name }}
            </a>
        </h3>

        @if ($location->type_list)
            <p class="mt-1 flex flex-wrap gap-1">
                @foreach ($location->type_list as $type)
                    <span class="rounded-sm bg-mustard/15 px-1.5 py-0.5 font-display text-[0.7rem] font-medium uppercase tracking-wide text-mustard">
                        {{ $type }}
                    </span>
                @endforeach
            </p>
        @endif

        <p class="mt-1.5 text-sm text-muted">
            @if ($location->area)
                {{-- `area` is comma-separated in the legacy data. --}}
                <span>{{ collect(explode(',', $location->area))->map(fn ($a) => trim($a))->filter()->join(', ') }}</span>
            @endif
            @if ($location->area && $location->address)
                <span aria-hidden="true" class="mx-1 text-rule">·</span>
            @endif
            @if ($location->address)
                <span>{{ $location->address }}</span>
            @endif
        </p>
    </div>

    <div class="hidden shrink-0 text-right sm:block">
        @if ($location->phone)
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $location->phone) }}"
               class="block text-sm text-brick hover:underline">{{ $location->phone }}</a>
        @endif
        @if ($location->average_rating)
            <p class="mt-1 text-sm text-muted">
                <span class="font-display font-medium text-ink">{{ $location->average_rating }}</span>/5
                <span class="text-xs">({{ $location->rating_votes }})</span>
            </p>
        @endif
    </div>
</div>
