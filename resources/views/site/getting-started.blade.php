{{--
    The Getting-started Path (/getting-started): its Published steps in order, step one marked
    as the place to start. Each step's Article says "Step N of M" and links the next.
--}}
<x-site.layout title="Getting-started Path" description="Set up First Mile Manager step by step, from signing up to your team’s first day.">
    @include('site.partials.listing-crumbs')

    <div class="listing">
        <header class="listing-head">
            <h1>Getting-started Path</h1>
            <p class="summary">Set up First Mile Manager step by step, from signing up to your team’s first day.</p>
        </header>

        @if ($path->isEmpty())
            <p class="listing-empty">There are no steps yet. <a href="/search">Search</a>, or start from the <a href="/">help centre home</a>.</p>
        @else
            <ol class="path-list">
                @foreach ($path as $step)
                    <li @class(['start' => $loop->first])>
                        <span class="step-n"><span class="visually-hidden">Step </span>{{ $loop->iteration }}</span>
                        <span class="step-what">
                            @if ($loop->first)
                                <span class="start-here">Start here</span>
                            @endif
                            <a href="{{ route('articles.show', $step->name, absolute: false) }}">{{ $step->title }}</a>
                            <span class="step-summary">{{ $step->summary }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-site.layout>
