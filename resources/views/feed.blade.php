<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<rss version="2.0">
    <channel>
        <title>{{ config('azp.site_name') }} - Restaurante {{ $cityName }}</title>
        <link>{{ url("/{$citySlug}/restaurante.html") }}</link>
        <description>Restaurante, pizzerii si localuri cu meniul zilei din {{ $cityName }}</description>
        <language>ro</language>
@foreach ($locations as $location)
        <item>
            <title>{{ trim(ucfirst((string) $location->type).' '.$location->name) }}</title>
            <link>{{ url($location->path) }}</link>
            <guid isPermaLink="true">{{ url($location->path) }}</guid>
            <description>{{ trim('Telefon: '.$location->phone.', Adresa: '.$location->address, ', ') }}</description>
@if ($location->register_date)
            <pubDate>{{ $location->register_date->toRfc2822String() }}</pubDate>
@endif
        </item>
@endforeach
    </channel>
</rss>
