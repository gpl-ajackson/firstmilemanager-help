{{--
    The "Help Preview" bar at the top of every page of a Help Preview (#59): what this copy is, that
    search and feedback do nothing here, and the way to its Draft Articles. No element ids (an
    Article page's only ids are its Section Names and main, ADR-0001).
--}}
@use('App\Preview\HelpPreview')
@php
    $drafts = app(App\Content\ContentLibrary::class)->draftArticles()->count();
    $pullRequest = HelpPreview::pullRequest();
@endphp
<div class="preview-bar" role="note" aria-label="Help Preview">
    <div class="wrap">
        <p><b>Help Preview</b> of {{ $pullRequest === null ? 'a pull request' : 'pull request #'.$pullRequest }}: its pages as readers will see them once it merges. Search and feedback do nothing here and nothing is recorded.
            <a href="{{ HelpPreview::DRAFTS_PAGE }}">{{ $drafts }} Draft {{ \Illuminate\Support\Str::plural('Article', $drafts) }}</a></p>
    </div>
</div>
