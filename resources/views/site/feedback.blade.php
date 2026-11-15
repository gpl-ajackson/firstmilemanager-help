{{--
    The page after answering "Did this answer your question?" without script. Its .feedback part
    is what the site's script swaps into the Article in place, so that part reads the same in
    both: Thanks after a Yes; after a No, what was wrong and the next steps.
--}}
@use('App\Records\FeedbackReason')
@use('App\Records\Records')
@use('App\Site\Addresses')
@php
    $headings = [
        'thanks' => 'Thanks',
        'what-was-wrong' => 'What was wrong?',
        'told-us' => 'Thanks for telling us',
        'too-many' => "Your answer wasn't counted",
        'unanswered' => 'Did this answer your question?',
    ];
    $chosen ??= null;
    $comment ??= null;
    $problem ??= null;
    $reference ??= null;
@endphp
<x-site.layout :title="$headings[$step]" noindex>
    <nav class="crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="/">Help centre</a></li>
            <li><a href="{{ Addresses::productArea($article->product_area) }}">{{ $article->product_area }}</a></li>
        </ol>
    </nav>

    <div class="feedback-page">
        <h1>Did “{{ $article->title }}” answer your question?</h1>

        @if ($step === 'unanswered')
            @include('site.partials.feedback-question')
        @else
            <section class="feedback" data-feedback>
                <h2 class="feedback-title" tabindex="-1">{{ $headings[$step] }}</h2>

                @if ($step === 'told-us')
                    <p>We read every answer and use them to put this help right.</p>
                @elseif ($step === 'too-many')
                    <p class="feedback-problem">This article has had several answers from you in the last few minutes, so this one wasn't counted. Please try again later.</p>
                @elseif ($step === 'what-was-wrong')
                    @if ($problem)
                        <p class="feedback-problem" role="alert">{{ $problem }}</p>
                    @endif
                    <form class="feedback-reason" method="post" action="{{ route('feedback.reason', $article->name, absolute: false) }}">
                        @if ($reference)
                            <input type="hidden" name="vote" value="{{ $reference }}">
                        @endif
                        <fieldset>
                            <legend>Choose a reason <span class="optional">(optional)</span></legend>
                            @foreach (FeedbackReason::cases() as $reason)
                                <label class="choice"><input type="radio" name="reason" value="{{ $reason->value }}" @checked($chosen === $reason)> {{ $reason->wording($forClients) }}</label>
                            @endforeach
                        </fieldset>
                        <label class="feedback-comment">
                            <span class="feedback-comment-label">Anything to add? <span class="optional">(optional)</span></span>
                            <span class="hint">Up to {{ Records::COMMENT_LIMIT }} characters. Please don't include names, emails or order details.</span>
                            <textarea name="comment" rows="4">{{ $comment }}</textarea>
                        </label>
                        <div><button class="btn btn-primary" type="submit">Send</button></div>
                    </form>
                @endif

                @if ($step === 'what-was-wrong' || $step === 'told-us')
                    <div class="feedback-next">
                        <h3>Try next</h3>
                        @if ($related->isNotEmpty())
                            <ul class="linklist" aria-label="Related articles">
                                @foreach ($related as $other)
                                    <li>
                                        <a href="{{ route('articles.show', $other->name, absolute: false) }}">
                                            <span>{{ $other->title }}</span>
                                            <span class="meta">{{ $other->type->value }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        {{-- No id: this part is swapped into the Article page, whose only ids are its Section Names. --}}
                        <x-site.search :id="false" />
                    </div>
                @endif
            </section>
        @endif

        <p class="feedback-back"><a href="{{ route('articles.show', $article->name, absolute: false) }}">Back to “{{ $article->title }}”</a></p>

        <x-site.still-stuck :article="$article" />
    </div>
</x-site.layout>
