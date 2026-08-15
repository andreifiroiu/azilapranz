<x-layout :title="$title" :description="$metaDescription">

    <div class="mx-auto max-w-3xl px-4 sm:px-6">

        <x-breadcrumbs :items="[['name' => $title]]" />

        <article class="pb-16">
            <h1 class="border-b border-rule pb-6 font-display text-4xl font-bold tracking-tight sm:text-5xl">
                {{ $title }}
            </h1>

            <div class="mt-8 max-w-prose leading-relaxed
                        [&_a]:text-brick [&_a]:underline
                        [&_h2]:mt-8 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-bold
                        [&_h3]:mt-6 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-medium
                        [&_li]:mt-1 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:pl-6
                        [&_p]:mt-4 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6">
                @if ($page && filled($page->content_html))
                    {{-- Sanitised in LegacyHtml::clean(). --}}
                    {!! $page->content_html !!}
                @else
                    <p>
                        Această pagină este în curs de actualizare.
                        Între timp poți vedea
                        <a href="{{ url('/'.config('azp.default_city').'/restaurante.html') }}">lista localurilor</a>.
                    </p>
                @endif
            </div>
        </article>
    </div>
</x-layout>
