{{--
    For your clients (/for-your-clients): the Client-tier Articles, for your staff to read and
    send to their clients by link, and the Client Glossary.
--}}
<x-site.layout title="For your clients" description="What your clients see when they sign in to your portal, written so you can send them the link, and the Client Glossary.">
    @include('site.partials.listing-crumbs')

    <div class="listing">
        <header class="listing-head">
            <h1>For your clients</h1>
            <p class="summary">What your clients see when they sign in, written for them, so you can send them the link.</p>
        </header>

        <section class="listing-group client-articles">
            <h2>Articles for your clients</h2>
            @if ($articles->isEmpty())
                <p class="listing-empty">There are no articles for your clients yet.</p>
            @else
                <ul class="linklist">
                    @foreach ($articles as $article)
                        @include('site.partials.listed-article', ['article' => $article, 'meta' => $article->product_area, 'inClientListing' => true])
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($entries->isNotEmpty())
            <section class="listing-group client-glossary">
                <h2><a href="{{ route('client-glossary', absolute: false) }}">Client Glossary</a></h2>
                <p class="listing-intro">The words your clients see on their screens, each explained to them in plain English.</p>
                <ul class="linklist">
                    @foreach ($entries as $entry)
                        <li><a href="{{ $entry->address() }}"><span>{{ $entry->term }}</span></a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-site.layout>
