@props(['items' => []])

<nav aria-label="Breadcrumb" class="py-4 text-sm">
    <ol class="flex flex-wrap items-center gap-1.5 text-muted">
        <li>
            <a href="{{ url('/') }}" class="hover:text-brick hover:underline">Acasă</a>
        </li>
        @foreach ($items as $item)
            <li aria-hidden="true" class="text-rule">/</li>
            <li @class(['text-ink' => $loop->last])>
                @if (! empty($item['url']) && ! $loop->last)
                    <a href="{{ $item['url'] }}" class="hover:text-brick hover:underline">{{ $item['name'] }}</a>
                @else
                    <span @if ($loop->last) aria-current="page" @endif>{{ $item['name'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
