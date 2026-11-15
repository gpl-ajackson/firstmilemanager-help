{{--
    One Glossary Entry (plan, "The Glossary"): the term, what else it is called, one or two plain
    sentences, where you'll see it, each value of a status with what it means, and the Articles
    that use it, worked out from their links. A Client Glossary entry lives under For your
    clients and links only to client pages. Entries have no feedback. Where you'll see it and each
    value's meaning are one line of Markdown, so a screen label in **bold** shows bold.
--}}
@use('Illuminate\Support\Str')
@php
    $inline = fn (string $text): string => Str::inlineMarkdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    $description = Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($entry->body_html))), 157);
    $alsoCalled = $entry->alsoCalled();
@endphp
<x-site.layout :title="$entry->term" :description="$description">
    <nav class="crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="/">Help centre</a></li>
            @if ($forClients)
                <li><a href="/for-your-clients">For your clients</a></li>
                <li><a href="{{ route('client-glossary', absolute: false) }}">Client Glossary</a></li>
            @else
                <li><a href="{{ route('glossary', absolute: false) }}">Glossary</a></li>
            @endif
        </ol>
    </nav>

    <article class="entry">
        <header class="entry-head">
            <h1>{{ $entry->term }}</h1>
            @if ($alsoCalled !== [])
                <p class="entry-also">Also called: @foreach ($alsoCalled as $name)<b>{{ $name }}</b>{{ $loop->last ? '.' : ', ' }}@endforeach</p>
            @endif
        </header>

        <div class="prose entry-body">
            {!! $entry->body_html !!}
        </div>

        <section class="entry-where">
            <h2>Where you’ll see it</h2>
            <p>{!! $inline($entry->where_you_see_it) !!}</p>
        </section>

        @if ($entry->status_values !== [])
            <section class="entry-values">
                <h2>What each value means</h2>
                <dl>
                    @foreach ($entry->status_values as $value)
                        <div>
                            <dt>
                                @if ($value['address'] !== null)
                                    <a href="{{ $value['address'] }}">{{ $value['value'] }}</a>
                                @else
                                    {{ $value['value'] }}
                                @endif
                            </dt>
                            <dd>{!! $inline($value['meaning']) !!}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if ($articles->isNotEmpty())
            <section class="entry-articles">
                <h2>Articles that use it</h2>
                <ul class="linklist">
                    @foreach ($articles as $article)
                        <li>
                            <a href="{{ route('articles.show', $article->name, absolute: false) }}">
                                <span>{{ $article->title }}</span>
                                <span class="meta">{{ $article->type->value }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
</x-site.layout>
