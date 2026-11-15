{{--
    "Did this answer your question?" (Article Feedback): a plain form, so it works without script.
    No session, so no CSRF token; the endpoint checks the origin instead. No element ids here: an
    Article page's only ids are its Section Names and main (ADR-0001).
--}}
@use('App\Preview\HelpPreview')
<section class="feedback" data-feedback>
    {{-- In a Help Preview the question shows but sends nothing: no address and its buttons disabled. --}}
    @if (HelpPreview::isOn())
    <form class="feedback-ask">
    @else
    <form class="feedback-ask" method="post" action="{{ route('feedback.vote', $article->name, absolute: false) }}">
    @endif
        <fieldset>
            <legend><h2 class="feedback-title">Did this answer your question?</h2></legend>
            <div class="feedback-answers">
                <button class="btn" type="submit" name="answer" value="yes" @disabled(HelpPreview::isOn())>Yes</button>
                <button class="btn" type="submit" name="answer" value="no" @disabled(HelpPreview::isOn())>No</button>
            </div>
            @if (HelpPreview::isOn())
                <p class="preview-inert">Feedback does nothing in a Help Preview.</p>
            @endif
        </fieldset>
    </form>
</section>
