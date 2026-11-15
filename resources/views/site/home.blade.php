{{--
    The help home in the Signboard layout (#14, layout A): a full-width ink band with search,
    then each block in the plan's order. On a phone every block stacks in one column in the
    same order. Prices, plans and what's coming are only ever links to the marketing site.
    For your clients and What's new sit side by side on a desktop, with "Look something up"
    across both below them.
--}}
@use('App\Site\Addresses')
@php
    $marketing = fn (string $path): string => $facts?->page($path) ?? 'https://firstmilemanager.co.uk'.$path;
@endphp
<x-site.layout title="How can we help?" description="Help with First Mile Manager: how to do each task, what its messages mean and what its words mean, for couriers’ staff and their clients." wide>
    <section class="home-search band">
        <div class="wrap">
            <h1>How can we help?</h1>
            <div class="corners">
                <x-site.search id="home-search" />
            </div>
        </div>
    </section>

    <div class="wrap home">
        <section class="home-block home-roles">
            <h2 class="block-title">Start with your role</h2>
            <ul class="role-tiles">
                @foreach ($roles as $role)
                    <li>
                        <a class="role-tile" href="{{ Addresses::rolePath($role) }}">
                            <strong>{{ $role->tile() }}</strong>
                            <span>{{ $role->blurb() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($path->isNotEmpty())
            <section class="home-block home-path">
                <div class="block-head">
                    <h2 class="block-title">Getting-started Path</h2>
                    <a class="see-all" href="/getting-started">See the whole path</a>
                </div>
                <p class="block-intro">From signing up to your team’s first day, in order.</p>
                <ol class="path-rail">
                    @foreach ($path as $step)
                        <li @class(['start' => $loop->first])>
                            <span class="step-n"><span class="visually-hidden">Step </span>{{ $loop->iteration }}</span>
                            <span class="step-what">
                                @if ($loop->first)
                                    <span class="start-here">Start here</span>
                                @endif
                                <a href="{{ route('articles.show', $step->name, absolute: false) }}">{{ $step->title }}</a>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        @if (count($areas) > 0)
        <section class="home-block home-areas">
            <h2 class="block-title">Product Areas</h2>
            <ul class="area-signs">
                @foreach ($areas as $area => $count)
                    <li>
                        <a class="area-sign" href="{{ Addresses::productArea($area) }}">
                            <strong>{{ $area }}</strong>
                            <span class="count">{{ $count }} <span class="visually-hidden">{{ Str::plural('article', $count) }}</span></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
        @endif

        <div class="home-two">
            @if ($clientArticles->isNotEmpty() || $hasClientGlossary)
                <section class="home-block home-clients">
                    <h2 class="block-title">For your clients</h2>
                    <p class="block-intro">What your clients see when they sign in, written so you can send them the link.</p>
                    <ul class="linklist">
                        @foreach ($clientArticles as $article)
                            <li><a href="{{ route('articles.show', $article->name, absolute: false) }}"><span>{{ $article->title }}</span></a></li>
                        @endforeach
                        @if ($hasClientGlossary)
                            <li><a href="{{ route('client-glossary', absolute: false) }}"><span>Client Glossary</span> <span class="meta">The words your clients see</span></a></li>
                        @endif
                    </ul>
                    <p><a class="see-all" href="/for-your-clients">See everything for your clients</a></p>
                </section>
            @endif

            @if ($releaseNotes->isNotEmpty())
                <section class="home-block home-whats-new">
                    <h2 class="block-title">What’s new</h2>
                    <p class="block-intro">What changed in First Mile Manager lately, newest first.</p>
                    <ul class="linklist">
                        @foreach ($releaseNotes as $note)
                            @include('site.partials.listed-release-note', ['note' => $note])
                        @endforeach
                    </ul>
                    <p><a class="see-all" href="{{ route('whats-new', absolute: false) }}">See everything new</a></p>
                </section>
            @endif

            <section class="home-block home-lookup">
                <h2 class="block-title">Look something up</h2>
                <ul class="linklist">
                    <li><a href="/troubleshooting"><span>Troubleshooting</span> <span class="meta">Fixes for First Mile Manager’s messages and problems</span></a></li>
                    <li><a href="{{ route('glossary', absolute: false) }}"><span>Glossary</span> <span class="meta">What First Mile Manager’s words mean</span></a></li>
                </ul>
            </section>
        </div>

        <section class="home-block home-new">
            <h2 class="block-title">New to First Mile Manager?</h2>
            <p>
                Follow the <a href="/getting-started">Getting-started Path</a> from signing up to your team’s first day,
                or read <a href="{{ $facts?->url ?? 'https://firstmilemanager.co.uk' }}">what First Mile Manager does</a>.
                For <a href="{{ $marketing('/early-access') }}">early access</a>, see the First Mile Manager website.
            </p>
        </section>
    </div>
</x-site.layout>
