{{--
    One Release Note (/whats-new/<yyyy-mm-dd>), titled by its date (plan, "Release Notes"): its
    summary, any words before the changes, then its changes grouped by Product Area in the app's
    menu order. Each change names the Team Roles it affects, is marked "Affects your clients"
    when it changes what clients see, and links the Articles it changed. Release Notes are
    history: no feedback, no "Still stuck?", no Checked On.
--}}
<x-site.layout :title="$note->title()" :description="$note->summary">
    <nav class="crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="/">Help centre</a></li>
            <li><a href="{{ route('whats-new', absolute: false) }}">What’s new</a></li>
        </ol>
    </nav>

    <article class="release-note">
        <header class="release-head">
            <h1>What’s new: <time datetime="{{ $note->released_on->toDateString() }}">{{ $note->dateInWords() }}</time></h1>
            <p class="summary">{{ $note->summary }}</p>
        </header>

        @if ($note->intro_html !== '')
            <div class="prose release-intro">
                {!! $note->intro_html !!}
            </div>
        @endif

        @foreach ($note->areas as $group)
            <section class="release-area">
                <h2>{{ $group['area'] }}</h2>
                <ul class="changes">
                    @foreach ($group['changes'] as $change)
                        <li class="change">
                            <div class="prose change-text">{!! $change['html'] !!}</div>
                            <p class="change-meta">
                                @if ($change['team_roles'] !== [])
                                    <span class="change-roles">For: {{ implode(', ', $change['team_roles']) }}</span>
                                @endif
                                @if ($change['affects_clients'])
                                    <span class="badge badge-client affects-clients">Affects your clients</span>
                                @endif
                            </p>
                            @if ($change['articles'] !== [])
                                <p class="change-articles">Read:
                                    @foreach ($change['articles'] as $article)
                                        <a href="{{ $article['address'] }}">{{ $article['title'] }}</a>{{ $loop->last ? '' : ',' }}
                                    @endforeach
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </article>
</x-site.layout>
