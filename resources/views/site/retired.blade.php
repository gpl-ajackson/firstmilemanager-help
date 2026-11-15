{{-- A Retired Article with no replacement (ADR-0001): what changed, and where to go now. Never indexed. --}}
<x-site.layout :title="$article->title.' (retired)'" :description="$article->what_changed" noindex>
    <section class="retired">
        <p class="retired-label">No longer in use</p>
        <h1>{{ $article->title }}</h1>
        <p class="retired-what-changed">{{ $article->what_changed }}</p>
        @if ($productAreaAddress !== null)
            <p>For help with {{ $article->product_area }} now, see <a href="{{ $productAreaAddress }}">everything in {{ $article->product_area }}</a>, or search for what you need.</p>
        @else
            <p>Search for what you need instead.</p>
        @endif
        <div class="corners">
            <x-site.search id="retired-search" />
        </div>
    </section>
</x-site.layout>
