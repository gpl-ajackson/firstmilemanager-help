{{--
    One Article in a listing: its title, then what helps a reader choose ($meta: its Article
    Type or Product Area), and "For your clients" on a Client-tier Article.
--}}
@use('App\Content\AudienceTier')
<li>
    <a href="{{ route('articles.show', $article->name, absolute: false) }}">
        <span>{{ $article->title }}</span>
        @if (($meta ?? null) !== null || $article->audience_tier === AudienceTier::Client)
            <span class="meta">
                {{ $meta ?? '' }}
                @if ($article->audience_tier === AudienceTier::Client && ! ($inClientListing ?? false))
                    <span class="badge badge-client">For your clients</span>
                @endif
            </span>
        @endif
    </a>
</li>
