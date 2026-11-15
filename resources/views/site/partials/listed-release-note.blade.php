{{--
    One Release Note in a list (What's new, and its block on the help home): its summary, its
    date, and "Affects your clients" when any of its changes changes what clients see.
--}}
<li>
    <a href="{{ $note->address() }}">
        <span>{{ $note->summary }}</span>
        <span class="meta">
            <time datetime="{{ $note->released_on->toDateString() }}">{{ $note->dateInWords() }}</time>
            @if ($note->affects_clients)
                <span class="badge badge-client">Affects your clients</span>
            @endif
        </span>
    </a>
</li>
