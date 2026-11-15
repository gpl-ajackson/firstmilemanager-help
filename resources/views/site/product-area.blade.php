{{--
    A Product Area (/areas/<area>), named as the app's menu: every Published Article in it, of
    both tiers, by Article Type. Client-tier Articles are marked "For your clients".
--}}
<x-site.layout :title="$area" :description="'Every First Mile Manager help article about '.$area.', by type: tasks, ideas, look-ups and fixes.'">
    @include('site.partials.listing-crumbs')

    <div class="listing">
        <header class="listing-head">
            <p class="listing-kind">Product Area</p>
            <h1>{{ $area }}</h1>
            <p class="summary">Every article about {{ $area }} in First Mile Manager, by type.</p>
        </header>

        @foreach ($byType as $type => $articles)
            <section class="listing-group">
                <h2>{{ $type }}</h2>
                <ul class="linklist">
                    @foreach ($articles as $article)
                        @include('site.partials.listed-article', ['article' => $article])
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-site.layout>
