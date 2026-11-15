{{--
    An Article in the Signboard layout (#14, layout A): one column, the answer first, related
    articles beside it on wide screens and after it on narrow ones. The parts follow the
    Article Templates by Article Type and Audience Tier. Only Section Names give elements ids.
--}}
@use('App\Content\ArticleStatus')
@use('App\Content\ArticleType')
@use('App\Content\AudienceTier')
@use('App\Site\Addresses')
@php
    $forClients = $article->audience_tier === AudienceTier::Client;
    // On a task, the Team Roles are who can do it; on the rest, who it is written for.
    $rolesLabel = in_array($article->type, [ArticleType::HowTo, ArticleType::GettingStarted], true) ? 'Who can do this' : 'For';
@endphp
<x-site.layout :title="$article->title" :description="$article->summary" :noindex="$article->joint_only">
    <nav class="crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="/">Help centre</a></li>
            <li><a href="{{ Addresses::productArea($article->product_area) }}">{{ $article->product_area }}</a></li>
        </ol>
    </nav>

    <div class="article-layout">
        <article class="article">
            <header class="article-head">
                @if ($placeInPath)
                    <p class="path-place"><a href="/getting-started">Getting-started Path</a>: step {{ $placeInPath->step }} of {{ $placeInPath->of }}</p>
                @endif
                @if ($article->status === ArticleStatus::Draft)
                    {{-- Only a Help Preview shows a Draft (#59). --}}
                    @if ($article->waiting_for_demo)
                        <p class="draft-marker"><span class="badge badge-draft">Waiting for the demo</span> Readers can’t see this Article yet: it shows once “{{ $article->feature }}” is on the demo.</p>
                    @else
                        <p class="draft-marker"><span class="badge badge-draft">Draft</span> Readers can’t see this Article yet: it shows only in Help Previews until its status is Published.</p>
                    @endif
                @endif
                <h1>{{ $article->title }}</h1>
                <ul class="details" aria-label="About this article">
                    <li><b>{{ $article->type->value }}</b></li>
                    <li>{{ $article->product_area }}</li>
                    @unless ($forClients || $article->team_roles === [])
                        <li>{{ $rolesLabel }}: <b>{{ implode(', ', $article->team_roles) }}</b></li>
                    @endunless
                    <li>Checked on <b>{{ $article->checked_on->format('j F Y') }}</b></li>
                </ul>
                <p class="summary">{{ $article->summary }}</p>
            </header>

            <div class="prose">
                {!! $article->body_html !!}
            </div>

            @if ($placeInPath)
                <nav class="next-step" aria-label="Next step">
                    @if ($placeInPath->next)
                        <p class="next-label">Next: step {{ $placeInPath->step + 1 }} of {{ $placeInPath->of }}</p>
                        <a href="{{ route('articles.show', $placeInPath->next->name, absolute: false) }}">{{ $placeInPath->next->title }}</a>
                    @else
                        <p>This is the last step of the Getting-started Path.</p>
                    @endif
                </nav>
            @endif

            @if ($forClients)
                <p class="client-close">Questions about your stock or deliveries go to your account manager.</p>
            @endif

            @include('site.partials.feedback-question')

            <x-site.still-stuck :article="$article" />
        </article>

        @if ($related->isNotEmpty())
            <aside class="related" aria-label="Related articles">
                <h2>Related articles</h2>
                <ul class="linklist">
                    @foreach ($related as $other)
                        <li>
                            <a href="{{ route('articles.show', $other->name, absolute: false) }}">
                                <span>{{ $other->title }}</span>
                                <span class="meta">{{ $other->type->value }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif
    </div>
</x-site.layout>
