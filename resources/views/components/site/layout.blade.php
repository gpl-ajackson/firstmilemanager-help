@props(['title', 'description' => null, 'noindex' => false, 'wide' => false])
@use('App\Site\Asset')
@use('App\Site\GoingPublic')
@use('App\Preview\HelpPreview')
@php
    // First Mile Manager's facts as the marketing site states them (content/facts.yaml); none before the first rebuild.
    $facts = app(App\Content\ContentLibrary::class)->facts();
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | First Mile Manager Help centre</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($noindex || ! GoingPublic::isOn() || HelpPreview::isOn())
        <meta name="robots" content="noindex">
    @endif
    <link rel="preload" href="{{ Asset::url('fonts/AtkinsonHyperlegibleNext-Variable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ Asset::url('css/brand.css') }}">
    <link rel="stylesheet" href="{{ Asset::url('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ Asset::url('css/site.css') }}">
    {{-- The site's one small script (public/js/site.js): it only improves pages that work without it.
         A Help Preview has none, so nothing on it asks anything of any server. --}}
    @unless (HelpPreview::isOn())
        <script src="{{ Asset::url('js/site.js') }}" defer></script>
    @endunless
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body>
    <a class="skip-link" href="#main">Skip to the content</a>
    @if (HelpPreview::isOn())
        @include('preview.bar')
    @endif
    <header class="site-head">
        <div class="wrap">
            <a class="logo-link" href="/">
                <picture>
                    <source srcset="{{ Asset::url('images/fmm-lockup-dark.svg') }}" media="(prefers-color-scheme: dark)">
                    <img src="{{ Asset::url('images/fmm-lockup-light.svg') }}" alt="First Mile Manager" width="195" height="30">
                </picture>
                <span class="logo-sub">Help centre</span>
            </a>
            <nav class="head-nav" aria-label="Help centre">
                <a href="/glossary">Glossary</a>
                <a href="/whats-new">What’s new</a>
                @if ($facts)
                    <a class="about" href="{{ $facts->url }}">About First Mile Manager</a>
                @endif
            </nav>
        </div>
    </header>
    {{-- Until alpha the facts' stage is "Early access", and every page says so (fmm#56). --}}
    @if ($facts?->isEarlyAccess())
        <aside class="early-access" aria-label="Early access">
            <div class="wrap">
                <p>{{ $facts->name }} is in early access. <a href="{{ $facts->page('/early-access') }}">Ask for early access</a></p>
            </div>
        </aside>
    @endif

    {{-- A wide page (the help home) lays out its own full-width bands, each with its own wrap. --}}
    <main id="main" @class(['wrap' => ! $wide, 'main-wide' => $wide])>
        {{ $slot }}
    </main>

    <footer class="site-foot">
        <div class="wrap">
            @if ($facts)
                <p>{{ $facts->description }}</p>
            @endif
            <p>This help centre stores nothing on your device. <a href="/privacy">What we record and how long we keep it</a></p>
            <p class="foot-links"><a href="{{ $facts?->url ?? 'https://firstmilemanager.co.uk' }}">firstmilemanager.co.uk</a></p>
        </div>
    </footer>
</body>
</html>
