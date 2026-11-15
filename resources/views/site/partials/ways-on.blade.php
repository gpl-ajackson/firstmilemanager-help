{{-- When search finds nothing: Troubleshooting, the Glossary and the Product Areas (spec #30, story 61). --}}
@use('App\Site\Addresses')
<ul class="linklist ways-on">
    <li><a href="/troubleshooting"><span>Troubleshooting</span> <span class="meta">Fixes for messages and problems</span></a></li>
    <li><a href="/glossary"><span>Glossary</span> <span class="meta">What First Mile Manager's words mean</span></a></li>
</ul>
@if ($productAreas !== [])
    <h3 class="ways-on-areas">Product Areas</h3>
    <ul class="linklist product-areas">
        @foreach ($productAreas as $area)
            <li><a href="{{ Addresses::productArea($area) }}"><span>{{ $area }}</span></a></li>
        @endforeach
    </ul>
@endif
