{{--
    A Help Preview's own page (#59): the pull request's Draft Articles, each marked as a draft. Readers
    of the help centre never see a Draft; only previews show them.
--}}
<x-site.layout title="Draft Articles in this Help Preview">
    <div class="listing">
        <header class="listing-head">
            <h1>Draft Articles in this Help Preview</h1>
            <p>Readers can’t see these yet. Each shows on the help centre once its status is Published.</p>
        </header>
        @if ($drafts->isEmpty())
            <p class="listing-empty">This pull request has no Draft Articles.</p>
        @else
            <ul class="linklist drafts">
                @foreach ($drafts as $draft)
                    <li>
                        <a href="{{ $draft->address() }}">
                            <span>{{ $draft->title }}</span>
                            <span class="meta"><span class="badge badge-draft">Draft</span> {{ $draft->type->value }} · {{ $draft->product_area }} · {{ $draft->audience_tier->value }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-site.layout>
