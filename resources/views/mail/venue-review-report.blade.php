@php
    use App\Support\GooglePlaces;

    // Wording per status, so the reader never has to decode a slug. Ordered by
    // GooglePlaces::NEEDS_REVIEW, which puts permanently-closed first.
    $labels = [
        GooglePlaces::CLOSED_PERMANENTLY => ['Închise definitiv', 'Google marchează afacerea ca închisă definitiv. De verificat și, dacă se confirmă, de suspendat.'],
        GooglePlaces::NOT_FOUND => ['Dispărute din Google', 'Locul nu mai există în baza Google. Poate însemna închidere, relocare sau doar o reorganizare la Google.'],
        GooglePlaces::CLOSED_TEMPORARILY => ['Închise temporar', 'Google marchează afacerea ca închisă temporar. De obicei nu necesită nicio acțiune.'],
        GooglePlaces::INVALID => ['Identificator invalid', 'Place ID-ul stocat este greșit. Se rezolvă de la sine la următoarea rulare azp:places:backfill.'],
    ];

    $groups = $venues->groupBy('place_id_status');
@endphp

<x-mail::message>
# Localuri de verificat

Localuri cu o stare nouă în Google: **{{ $venues->count() }}**.

@foreach ($labels as $status => [$heading, $explanation])
@if ($groups->has($status))
## {{ $heading }} ({{ $groups[$status]->count() }})

{{ $explanation }}

<x-mail::table>
| Local | Oraș | Pagină |
|:------|:-----|:-------|
@foreach ($groups[$status] as $venue)
| {{ str_replace('|', '\|', (string) $venue->name) }} | {{ $venue->city }} | [{{ $venue->slug ?: '#'.$venue->id }}]({{ url($venue->path) }}) |
@endforeach
</x-mail::table>

@endif
@endforeach

Verificarea nu modifică singură coloana `status` — localurile de mai sus sunt
încă în listări, iar paginile lor răspund în continuare 200.

Ca să le scoți din listări, rulează `php artisan azp:places:sync-status`.
Comanda le trece pe `closed`: dispar din listări, dar pagina rămâne activă la
aceeași adresă și anunță închiderea. Suspendarea (410) rămâne o decizie
manuală, pentru că URL-urile acestea sunt indexate.

Fiecare local apare o singură dată, atunci când starea lui se schimbă.

<x-mail::button :url="url('/')">
Deschide site-ul
</x-mail::button>

{{ config('azp.site_name') }}
</x-mail::message>
