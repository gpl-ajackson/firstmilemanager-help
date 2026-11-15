{{--
    What's new (/whats-new): every Release Note, newest first, by its summary and date
    (plan, "Release Notes"). For couriers’ staff; a Release Note with a change that affects clients
    says so, so Account Managers know which to read first.
--}}
<x-site.layout title="What’s new" description="What changed in First Mile Manager with each release, newest first, and which changes your clients will notice.">
    @include('site.partials.listing-crumbs')

    <div class="listing">
        <header class="listing-head">
            <h1>What’s new</h1>
            <p class="summary">What changed in First Mile Manager with each release, newest first. Changes marked “Affects your clients” change what your clients see.</p>
        </header>

        @if ($notes->isEmpty())
            <p class="listing-empty">No Release Notes yet. Each time First Mile Manager changes, what changed is written up here.</p>
        @else
            <ul class="linklist release-notes">
                @foreach ($notes as $note)
                    @include('site.partials.listed-release-note', ['note' => $note])
                @endforeach
            </ul>
        @endif
    </div>
</x-site.layout>
