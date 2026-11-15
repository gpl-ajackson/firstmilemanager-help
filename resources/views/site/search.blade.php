{{-- /search: the results page (spec #30, "Search"). Works without script; never indexed. --}}
<x-site.layout :title="$words === '' ? 'Search' : 'Search for “'.$words.'”'" noindex>
    <section class="search-page">
        <h1>Search</h1>
        <x-site.search id="search-page" :value="$words" />

        @if ($results === null)
            <div class="search-none">
                <h2>Search isn't working right now</h2>
                <p>Try again in a few minutes, or find your answer another way:</p>
                @include('site.partials.ways-on')
            </div>
        @elseif ($words === '')
            <p class="search-hint">Type a question or a few words, or paste a message from First Mile Manager.</p>
        @elseif ($results->isEmpty())
            <div class="search-none">
                <h2>No results for “{{ $words }}”</h2>
                <p>Try other words, or find your answer another way:</p>
                @include('site.partials.ways-on')
            </div>
        @else
            @if ($results->glossaryAnswers !== [])
                {{-- A term or Other Name the reader typed, translated on top (spec #30, user stories 51 and 52). --}}
                <section class="glossary-answers" aria-label="In the Glossary">
                    @foreach ($results->glossaryAnswers as $answer)
                        <div class="glossary-answer">
                            <h2 class="answer-term"><a href="{{ $answer->address }}" rel="nofollow">{{ $answer->term }}</a></h2>
                            @if ($answer->alsoCalled !== null)
                                <p class="answer-also">Also called <b>{{ $answer->alsoCalled }}</b>.
                                    {{ $answer->isForClients() ? 'Your courier calls this' : 'In First Mile Manager this is' }} {{ $answer->article() }} <b>{{ $answer->term }}</b>.</p>
                            @endif
                            <div class="prose answer-body">{!! $answer->definitionHtml !!}</div>
                            <ul class="result-meta">
                                <li>{{ $answer->isForClients() ? 'Client Glossary' : 'Glossary' }}</li>
                                @if ($answer->isForClients())
                                    <li class="badge badge-client">For your clients</li>
                                @endif
                            </ul>
                        </div>
                    @endforeach
                </section>
            @endif
            @if ($results->results !== [])
                @php($articles = count($results->results))
                <p class="results-count" role="status">{{ $articles }} {{ Str::plural('result', $articles) }} for “{{ $words }}”</p>
                <ol class="results">
                    @foreach ($results->results as $result)
                        <li class="result">
                            <h2 class="result-title"><a href="{{ $result->address }}" rel="nofollow">{{ $result->title }}</a></h2>
                            @if ($result->sectionHeading !== null)
                                <p class="result-section">Opens at <b>{{ $result->sectionHeading }}</b></p>
                            @endif
                            <p class="result-summary">{{ $result->summary }}</p>
                            <ul class="result-meta">
                                <li class="result-type">{{ $result->type->value }}</li>
                                <li class="result-area">{{ $result->productArea }}</li>
                                @if ($result->isForClients())
                                    <li class="badge badge-client">For your clients</li>
                                @endif
                            </ul>
                        </li>
                    @endforeach
                </ol>
            @endif
        @endif
    </section>
</x-site.layout>
