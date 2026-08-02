{{--
    One page, two jobs: the share card for one of Troy's own reviews (mode 'entry', reached
    from the CP or from a review's own page) and the public generator for a review published
    anywhere (mode 'generator'). They share the card, the controls and the render pipeline —
    there is no second implementation.

    The generator is server-rendered in two steps, 'choose' then 'build', rather than a
    client-side wizard: the crawl has to happen on the server anyway, and a page load between
    steps costs nothing and keeps the thing working with plain form posts.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @if ($mode === 'generator')
        <meta name="description" content="Turn a review of your Fringe show into an image for Instagram. Paste a link to a review on Edmonton Journal, 12thNight or troypavlek.ca, or type it in yourself.">
    @else
        <meta name="robots" content="noindex">
    @endif
    <script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/html-to-image@1.11.11/dist/html-to-image.js"></script>
    @include('fringe.social-card._styles')
    @if ($step === 'build')
        {{-- Plain script rather than @js() in the x-data attribute: Alpine is deferred, so
             this has already run by the time it initialises, and the config stays readable
             in the page source instead of being unicode-escaped into an attribute. --}}
        <script>window.cardConfig = @json($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);</script>
    @endif
</head>
<body @if ($step === 'build') x-data="cardBuilder(window.cardConfig)" @endif>

<header class="masthead">
    <p class="eyebrow">{{ $eyebrow }}</p>
    <h1>{{ $heading }}</h1>
    @if ($showLine)
        <p class="show-line">
            {{ $showLine }}
            @if ($stars)
                &nbsp;<span class="stars">{{ $stars }}</span>
            @endif
        </p>
    @endif
    <p class="lede">{{ $lede }}</p>
</header>

@if (session('success'))
    <div class="flash">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="flash flash-error" role="alert">
        <strong>{{ $step === 'choose' ? "That didn't work." : "That didn't save." }}</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($step === 'choose')
    <div class="chooser">
        <form method="POST" action="{{ $buildUrl }}" class="url-form">
            @csrf
            <label for="url"><strong>Paste a link to the review</strong></label>
            <div class="url-row">
                <input type="url" id="url" name="url" value="{{ old('url') }}" placeholder="https://…" required>
                <button type="submit" class="btn btn-primary">Fetch the review</button>
            </div>
            <p class="sources">
                Works with <strong>Edmonton Journal</strong>, <strong>12thNight.ca</strong> and
                <strong>troypavlek.ca</strong>. It'll pull out quotes, ratings and the
                artwork where the site publishes them. You can edit any part of it before generating your image.
            </p>
        </form>

        <div class="chooser-options">
            <form method="POST" action="{{ $buildUrl }}" style="flex: 1 1 18rem;">
                @csrf
                <input type="hidden" name="manual" value="1">
                <button type="submit" class="chooser-card" style="width: 100%;">
                    <h2>Or type it in yourself &rarr;</h2>
                    <p>Reviewed somewhere else, or in print? Start from a blank card and fill in
                       the quote, rating and attribution by hand.</p>
                </button>
            </form>
        </div>
    </div>
@else
    <div class="builder">
        <section class="stage-pane" aria-label="Card preview">
            <div class="format-tabs" role="tablist">
                <button type="button" :class="format === 'feed' && 'active'" @click="format = 'feed'">Feed post</button>
                <button type="button" :class="format === 'story' && 'active'" @click="format = 'story'">Story</button>
                @if ($canSave)
                    <button type="button" :class="format === 'og' && 'active'" @click="format = 'og'">OpenGraph</button>
                @endif
            </div>

            <div class="stage">
                <div class="preview-frame" :style="`width: ${cardWidth * previewScale}px; height: ${cardHeight * previewScale}px`">
                    <div class="preview-scale" :style="`transform: scale(${previewScale})`">
                        @include('fringe.social-card._card')
                    </div>
                </div>
            </div>

            <p class="stage-caption" x-text="cardWidth + ' × ' + cardHeight + ' px · PNG'"></p>
        </section>

        @if ($mode === 'entry')
            <form class="controls" method="POST" action="{{ $saveUrl }}" enctype="multipart/form-data">
                @csrf
                @include('fringe.social-card._controls')

                <div class="actions">
                    {{-- OpenGraph mode has one job: render the card and hang it on the entry.
                         Downloading or saving card settings isn't what you're there for. --}}
                    <template x-if="format !== 'og'">
                        <button type="button" class="btn btn-primary" @click="download" x-text="downloading ? 'Rendering…' : 'Download PNG'"></button>
                    </template>
                    @if ($canSave)
                        <template x-if="format !== 'og'">
                            <button type="submit" class="btn btn-secondary">Save to entry</button>
                        </template>
                        <template x-if="format === 'og'">
                            <button type="button" class="btn btn-primary" @click="setOgImage" :disabled="settingOg" x-text="settingOg ? 'Uploading…' : 'Set as OG image'"></button>
                        </template>
                    @endif
                    <a href="{{ $backUrl }}" class="btn btn-link">{{ $backLabel }}</a>
                </div>
                @if ($canSave)
                    {{-- Whether this show already has an OpenGraph image, so you know before
                         you overwrite one — setOgImage replaces it rather than adding. --}}
                    <p class="og-status" x-show="format === 'og'">
                        @if ($existingOgUrl)
                            This show already has an OpenGraph image.
                            <a href="{{ $existingOgUrl }}" target="_blank" rel="noopener">View it in a new tab &rarr;</a>
                            Setting one replaces it.
                        @else
                            This show has no OpenGraph image yet, so shares fall back to the generic Fringe artwork.
                        @endif
                    </p>
                    <p class="og-status" x-show="ogMessage" x-text="ogMessage" role="status"></p>
                @endif
            </form>
        @else
            <div class="controls">
                @if ($warning)
                    <div class="notice notice-warn" role="status">{{ $warning }}</div>
                @endif
                @include('fringe.social-card._controls')

                <div class="actions">
                    <button type="button" class="btn btn-primary" @click="download" x-text="downloading ? 'Rendering…' : 'Download PNG'"></button>
                    <a href="{{ $backUrl }}" class="btn btn-link">{{ $backLabel }}</a>
                </div>
            </div>
        @endif
    </div>

    {{-- Outside .builder so the overlay covers the page rather than one column. --}}
    @include('fringe.social-card._quote-picker')
@endif

@if ($step === 'build')
    @include('fringe.social-card._builder')
@endif
</body>
</html>
