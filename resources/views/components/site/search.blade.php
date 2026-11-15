@props(['id' => 'search', 'value' => ''])
{{--
    The help centre's search box: a plain form that works without script. With :id="false" it
    gives no element an id (for a box inside an Article page, whose only ids are its Section
    Names, ADR-0001): the box is named by aria-label instead of a label's "for".
--}}
@use('App\Preview\HelpPreview')
{{-- In a Help Preview the box shows but sends nothing: no address, and its button is disabled, so Enter does nothing too. --}}
@if (HelpPreview::isOn())
<form class="search" role="search">
@else
<form class="search" role="search" action="/search" method="get">
@endif
    @if ($id)
        <label class="search-label" for="{{ $id }}">Search the help centre</label>
    @else
        <span class="search-label" aria-hidden="true">Search the help centre</span>
    @endif
    <div class="search-row">
        <input {!! $id ? 'id="'.e($id).'"' : 'aria-label="Search the help centre"' !!} name="q" type="search" value="{{ $value }}" autocomplete="off"
               placeholder="Search, or paste a message from First Mile Manager">
        <button class="btn btn-primary" type="submit" @disabled(HelpPreview::isOn())>Search</button>
    </div>
    @if (HelpPreview::isOn())
        <p class="preview-inert">Search does nothing in a Help Preview.</p>
    @endif
</form>
