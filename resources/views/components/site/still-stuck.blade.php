@props(['article'])
@use('App\Content\AudienceTier')
@use('App\Preview\HelpPreview')
{{-- "Still stuck?": clients go to their own courier, never to us; staff reach us through the counted address. --}}
@if ($article->audience_tier === AudienceTier::Client)
    <p class="still-stuck"><b>Still stuck?</b> Contact your courier’s account manager.</p>
@elseif (HelpPreview::isOn())
    {{-- A Help Preview counts nothing, so it links the email itself. --}}
    <p class="still-stuck"><b>Still stuck?</b> <a href="mailto:{{ config('help.still_stuck_email') }}?subject={{ rawurlencode($article->title) }}" rel="nofollow">Email {{ config('help.still_stuck_email') }}</a></p>
@else
    <p class="still-stuck"><b>Still stuck?</b> <a href="{{ route('still-stuck', $article->name, absolute: false) }}" rel="nofollow">Email {{ config('help.still_stuck_email') }}</a></p>
@endif
