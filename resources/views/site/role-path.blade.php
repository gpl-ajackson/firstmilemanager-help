{{--
    A Role Path (/roles/<role>): every staff Article tagged with the role, grouped by
    Product Area in the app's menu order, Getting-started and How-to Articles first in each.
--}}
@use('App\Site\Addresses')
<x-site.layout :title="$role->title()" :description="'Help for the '.$role->title().' role in First Mile Manager: '.lcfirst($role->blurb())">
    @include('site.partials.listing-crumbs')

    <div class="listing">
        <header class="listing-head">
            <p class="listing-kind">{{ $role->tile() }}</p>
            <h1>{{ $role->title() }}</h1>
            <p class="summary">{{ $role->blurb() }} Every article for your role, by Product Area, with the tasks first.</p>
        </header>

        @forelse ($groups as $area => $articles)
            <section class="listing-group">
                <h2><a href="{{ Addresses::productArea($area) }}">{{ $area }}</a></h2>
                <ul class="linklist">
                    @foreach ($articles as $article)
                        @include('site.partials.listed-article', ['article' => $article, 'meta' => $article->type->value])
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="listing-empty">There are no articles for this role yet. <a href="/search">Search</a>, or start from the <a href="/">help centre home</a>.</p>
        @endforelse
    </div>
</x-site.layout>
