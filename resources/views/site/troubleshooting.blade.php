{{--
    Troubleshooting (/troubleshooting): every Troubleshooting Article, grouped by Product Area
    in the app's menu order, so a reader finds a fix without knowing its title.
--}}
@use('App\Site\Addresses')
<x-site.layout title="Troubleshooting" description="Fixes for First Mile Manager messages and problems, by Product Area. Paste a message from First Mile Manager into search to find its fix.">
    @include('site.partials.listing-crumbs')

    <div class="listing">
        <header class="listing-head">
            <h1>Troubleshooting</h1>
            <p class="summary">What First Mile Manager’s messages mean and how to fix each problem, by Product Area. You can also paste a message from First Mile Manager into search.</p>
            <x-site.search id="troubleshooting-search" />
        </header>

        @forelse ($groups as $area => $articles)
            <section class="listing-group">
                <h2><a href="{{ Addresses::productArea($area) }}">{{ $area }}</a></h2>
                <ul class="linklist">
                    @foreach ($articles as $article)
                        @include('site.partials.listed-article', ['article' => $article])
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="listing-empty">There are no troubleshooting articles yet.</p>
        @endforelse
    </div>
</x-site.layout>
