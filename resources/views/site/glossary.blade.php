{{--
    The Glossary, or the Client Glossary (under For your clients): every entry of one tier,
    A to Z, under its letter.
--}}
@php
    $title = $forClients ? 'Client Glossary' : 'Glossary';
    $intro = $forClients
        ? 'The words you’ll see when you sign in to your courier’s portal, each explained in plain English.'
        : 'The words you’ll see in First Mile Manager, each explained in plain English, A to Z.';
@endphp
<x-site.layout :title="$title" :description="$intro">
    <nav class="crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="/">Help centre</a></li>
            @if ($forClients)
                <li><a href="/for-your-clients">For your clients</a></li>
            @endif
        </ol>
    </nav>

    <div class="glossary">
        <header class="glossary-head">
            <h1>{{ $title }}</h1>
            <p class="summary">{{ $intro }}</p>
        </header>

        @forelse ($byLetter as $letter => $entries)
            <section class="glossary-letter" aria-label="{{ $letter }}">
                <h2>{{ $letter }}</h2>
                <ul class="linklist">
                    @foreach ($entries as $entry)
                        <li>
                            <a href="{{ $entry->address() }}">
                                <span>{{ $entry->term }}</span>
                                @if ($entry->alsoCalled() !== [])
                                    <span class="meta">Also called {{ implode(', ', $entry->alsoCalled()) }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p>There are no entries yet.</p>
        @endforelse
    </div>
</x-site.layout>
