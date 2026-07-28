<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Share this review — {{ $title }}</title>
    <script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/html-to-image@1.11.11/dist/html-to-image.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --teal: #008483;
            --teal-dark: #006c6b;
            --teal-wash: #eaf4f3;
            --ink: #1f2937;
            --ink-soft: #6b7280;
            --line: #e4e4e0;
            --paper: #fafaf8;
            --stage: #10201f;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--paper); color: var(--ink); }

        /* ---- Masthead ---- */
        .masthead { padding: 1.75rem 2rem 1.5rem; border-bottom: 1px solid var(--line); background: white; }
        .masthead .eyebrow { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--teal); margin-bottom: 0.4rem; }
        .masthead h1 { font-family: Georgia, 'Times New Roman', serif; font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
        .masthead .show-line { margin-top: 0.35rem; color: var(--ink-soft); font-size: 0.95rem; }
        .masthead .show-line .stars { color: var(--teal); letter-spacing: 2px; }
        .masthead .lede { margin-top: 0.6rem; font-size: 0.9rem; color: var(--ink-soft); max-width: 46rem; }

        .flash { background: var(--teal-wash); border: 1px solid var(--teal); color: var(--teal-dark); padding: 0.6rem 1rem; border-radius: 8px; margin: 1.25rem 2rem 0; font-size: 0.9rem; }
        .flash-error { background: #fdf2f2; border-color: #c53030; color: #9b2c2c; }
        .flash-error ul { margin: 0.35rem 0 0 1.1rem; }

        /* ---- Two-pane layout ---- */
        .builder { display: flex; gap: 2.5rem; padding: 2rem; align-items: flex-start; flex-wrap: wrap; }
        .stage-pane { flex: 1 1 380px; display: flex; flex-direction: column; align-items: center; min-width: 0; }
        .controls { flex: 1 1 320px; max-width: 460px; }

        /* ---- Preview stage ---- */
        .format-tabs { display: flex; gap: 0.4rem; background: white; border: 1px solid var(--line); border-radius: 999px; padding: 0.3rem; margin-bottom: 1.1rem; }
        .format-tabs button { border: none; background: none; font: inherit; font-size: 0.85rem; font-weight: 600; color: var(--ink-soft); padding: 0.4rem 1.1rem; border-radius: 999px; cursor: pointer; }
        .format-tabs button.active { background: var(--teal); color: white; }
        .stage { width: 100%; min-height: 620px; background: var(--stage); border-radius: 14px; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
        .preview-frame { overflow: hidden; border-radius: 4px; box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45); }
        .preview-scale { transform-origin: top left; }
        .stage-caption { margin-top: 0.8rem; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--ink-soft); }

        /* ---- Controls ---- */
        .group { background: white; border: 1px solid var(--line); border-radius: 12px; padding: 1.25rem 1.35rem; margin-bottom: 1.1rem; }
        .group-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--teal); margin-bottom: 0.9rem; }
        .field { margin-bottom: 1rem; }
        .field:last-child { margin-bottom: 0; }
        .field > label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--ink); margin-bottom: 0.35rem; }
        .text-input { width: 100%; padding: 0.55rem 0.8rem; border: 1px solid var(--line); border-radius: 8px; font: inherit; font-size: 0.95rem; background: white; }
        .group-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.9rem; }
        .group-head .group-title { margin-bottom: 0; }
        .switch { position: relative; display: inline-block; width: 2.5rem; height: 1.4rem; cursor: pointer; }
        .switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
        .switch-track { position: absolute; inset: 0; background: #cfcfc9; border-radius: 999px; transition: background 0.15s; pointer-events: none; }
        .switch-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: calc(1.4rem - 4px); height: calc(1.4rem - 4px); background: white; border-radius: 50%; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25); transition: transform 0.15s; }
        .switch input:checked + .switch-track { background: var(--teal); }
        .switch input:checked + .switch-track::after { transform: translateX(1.1rem); }
        .switch input:focus-visible + .switch-track { outline: 2px solid var(--teal); outline-offset: 2px; }
        .field .hint { font-size: 0.78rem; color: var(--ink-soft); margin-top: 0.3rem; line-height: 1.4; }
        textarea#quote { width: 100%; min-height: 7.5rem; padding: 0.7rem 0.8rem; border: 1px solid var(--line); border-radius: 8px; font-family: Georgia, 'Times New Roman', serif; font-size: 1.05rem; line-height: 1.45; resize: vertical; background: white; }
        .char-count { float: right; font-weight: 400; color: var(--ink-soft); font-size: 0.78rem; }
        input[type=range] { width: 100%; accent-color: var(--teal); }
        input[type=range]:disabled { opacity: 0.35; }
        .row { display: flex; gap: 1.1rem; }
        .row .field { flex: 1; }
        .stepper { display: flex; align-items: stretch; max-width: 10rem; }
        .stepper button { width: 2.4rem; border: 1px solid var(--line); background: var(--paper); font: inherit; font-size: 1.1rem; cursor: pointer; color: var(--ink); }
        .stepper button:first-child { border-radius: 8px 0 0 8px; }
        .stepper button:last-child { border-radius: 0 8px 8px 0; }
        .stepper input { width: 100%; text-align: center; border: 1px solid var(--line); border-left: none; border-right: none; font: inherit; font-size: 0.9rem; padding: 0.45rem 0; -moz-appearance: textfield; background: white; }
        .stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; }
        .line-picker { display: flex; flex-direction: column; gap: 0.35rem; max-height: 13rem; overflow-y: auto; }
        .line-pick { text-align: left; font-family: Georgia, 'Times New Roman', serif; font-size: 0.92rem; line-height: 1.4; color: var(--ink); background: var(--paper); border: 1px solid var(--line); border-radius: 8px; padding: 0.5rem 0.7rem; cursor: pointer; }
        .line-pick:hover { border-color: var(--teal); background: var(--teal-wash); }
        input[type=file] { width: 100%; font-size: 0.85rem; color: var(--ink-soft); }
        input[type=file]::file-selector-button { font: inherit; font-size: 0.85rem; font-weight: 600; color: var(--teal); background: var(--teal-wash); border: 1px solid var(--teal); border-radius: 8px; padding: 0.4rem 0.9rem; margin-right: 0.8rem; cursor: pointer; }

        /* ---- Actions ---- */
        .actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-top: 1.4rem; }
        .btn { padding: 0.7rem 1.4rem; border-radius: 8px; border: none; font: inherit; font-size: 0.95rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--teal); color: white; }
        .btn-primary:hover { background: var(--teal-dark); }
        .btn-secondary { background: white; color: var(--teal); border: 1px solid var(--teal); }
        .btn-secondary:hover { background: var(--teal-wash); }
        .btn-link { background: none; color: var(--ink-soft); font-weight: 400; padding: 0.7rem 0.5rem; }
        .btn-link:hover { color: var(--ink); }
        .og-status { margin-top: 0.7rem; font-size: 0.85rem; color: var(--teal-dark); }
        button.btn-inline { border: none; background: none; font: inherit; font-size: inherit; color: var(--teal); cursor: pointer; padding: 0; text-decoration: underline; }
        :focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }

        /* ---- The card itself: this exact node is what gets rasterized ---- */
        .card { position: relative; overflow: hidden; background: #111; }
        .card .bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .card .overlay { position: absolute; inset: 0; }
        .card .panel { position: absolute; left: 0; width: 100%; padding: 56px 60px; }
        .card .stars { font-size: 72px; color: white; letter-spacing: 6px; line-height: 1; margin-bottom: 28px; }
        .card .watch-heading { display: inline-block; font-size: 34px; font-weight: 700; color: #a6d0cf; text-transform: uppercase; letter-spacing: 5px; line-height: 1; background: rgba(166, 208, 207, 0.3); border: 3px solid #a6d0cf; border-radius: 12px; padding: 16px 30px 14px; margin-bottom: 32px; }
        .card .quote { font-family: Georgia, 'Times New Roman', serif; color: white; white-space: pre-line; line-height: 1.25; }
        .card .quote .quote-mark { font-size: 1.5em; line-height: 0; opacity: 0.55; position: relative; top: 0.22em; }
        .card .quote .quote-mark.open { margin-right: 0.08em; }
        .card .quote .quote-mark.close { margin-left: 0.08em; }
        .card .attribution { margin-top: 32px; font-size: 34px; color: rgba(255, 255, 255, 0.85); font-style: italic; }

        @media (max-width: 720px) {
            .masthead { padding: 1.25rem 1rem; }
            .builder { padding: 1rem; gap: 1.5rem; }
            .flash { margin: 1rem 1rem 0; }
            .stage { min-height: 0; }
            .controls { max-width: none; }
        }
    </style>
</head>
<body x-data="cardBuilder()">

<header class="masthead">
    <p class="eyebrow">Troy's Fringe Reviews</p>
    <h1>Share this review</h1>
    <p class="show-line">
        {{ $title }}
        @if ($stars)
            &nbsp;<span class="stars">{{ $stars }}</span>
        @endif
    </p>
    @if ($canSave)
        <p class="lede">Tweak the card, save the settings to the entry, and download the PNG.</p>
    @else
        <p class="lede">Turn this review into an image for Instagram. Pick your favourite line, adjust the layout, and download.</p>
    @endif
</header>

@if (session('success'))
    <div class="flash">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="flash flash-error" role="alert">
        <strong>That didn't save.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

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
                    <div class="card" id="card" :style="`width: ${cardWidth}px; height: ${cardHeight}px`">
                        <img class="bg" :src="bgUrl" x-show="bgUrl" :style="`object-position: ${focalX}% ${focalY}%`" @load="measureImage($event.target)" alt="">
                        <div class="overlay" :style="scrimStyle">
                            <div class="panel" :style="`top: ${position}%; transform: translateY(-${position}%)`">
                                @if ($stars)
                                    <div class="stars">{{ $stars }}</div>
                                @elseif ($watchlist)
                                    <div class="watch-heading">Show to watch</div>
                                @endif
                                <div class="quote" :style="`font-size: ${textSize}px`"><span class="quote-mark open">&ldquo;</span><span x-text="quote"></span><span class="quote-mark close">&rdquo;</span></div>
                                <div class="attribution" x-show="attributionEnabled" :style="`font-size: ${attributionSize}px`" x-text="attributionText"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="stage-caption" x-text="cardWidth + ' × ' + cardHeight + ' px · PNG'"></p>
    </section>

    <form class="controls" method="POST" action="{{ $saveUrl }}" enctype="multipart/form-data">
        @csrf

        <div class="group">
            <p class="group-title">Quote</p>
            <div class="field">
                <label for="quote">Your favourite line from the review <span class="char-count"><span x-text="quote.length"></span>/280</span></label>
                <textarea id="quote" name="quote" maxlength="280" x-model="quote"></textarea>
                <p class="hint">Press Return to set your own line breaks &mdash; they appear on the card exactly as typed.</p>
            </div>
            <div class="field">
                <label>Or take a line straight from the review</label>
                <div class="line-picker">
                    <template x-for="(line, index) in reviewLines" :key="index">
                        <button type="button" class="line-pick" @click="quote = line" x-text="line"></button>
                    </template>
                </div>
                <p class="hint">Tap a sentence to make it the quote &mdash; you can still edit it above.</p>
            </div>
        </div>

        <div class="group">
            <p class="group-title">Layout</p>
            <div class="row">
                <div class="field">
                    <label for="position">Text position</label>
                    <input type="range" id="position" name="position" min="0" max="100" x-model="position" aria-label="Vertical text position, 0 is top, 100 is bottom">
                    <p class="hint">Slide to move the quote from the top of the card to the bottom.</p>
                </div>
                <div class="field">
                    <label for="text_size">Text size</label>
                    <div class="stepper">
                        <button type="button" @click="textSize = Math.max(20, textSize - 2)" aria-label="Smaller text">&minus;</button>
                        <input type="number" id="text_size" name="text_size" min="20" max="150" x-model.number="textSize" aria-label="Text size in pixels">
                        <button type="button" @click="textSize = Math.min(150, textSize + 2)" aria-label="Larger text">+</button>
                    </div>
                    <p class="hint">Long quotes read better a couple of sizes smaller.</p>
                </div>
            </div>
        </div>

        <div class="group">
            <div class="group-head">
                <p class="group-title">Attribution</p>
                <label class="switch">
                    <input type="checkbox" x-model="attributionEnabled" aria-label="Show the attribution line on the card">
                    <span class="switch-track" aria-hidden="true"></span>
                </label>
            </div>
            <input type="hidden" name="attribution_enabled" :value="attributionEnabled ? 1 : 0">
            <input type="hidden" name="attribution_text" :value="attributionText">
            <input type="hidden" name="attribution_size" :value="attributionSize">
            <div x-show="attributionEnabled">
                <div class="field">
                    <label for="attribution_text">Attribution text</label>
                    <input type="text" id="attribution_text" class="text-input" maxlength="120" x-model="attributionText">
                </div>
                <div class="field">
                    <label for="attribution_size">Text size</label>
                    <div class="stepper">
                        <button type="button" @click="attributionSize = Math.max(16, attributionSize - 2)" aria-label="Smaller attribution text">&minus;</button>
                        <input type="number" id="attribution_size" min="16" max="80" x-model.number="attributionSize" aria-label="Attribution text size in pixels">
                        <button type="button" @click="attributionSize = Math.min(80, attributionSize + 2)" aria-label="Larger attribution text">+</button>
                    </div>
                </div>
            </div>
            <p class="hint" x-show="!attributionEnabled">The attribution line is hidden from the card.</p>
        </div>

        <div class="group">
            <p class="group-title">Background</p>
            <div class="field">
                <label for="image">Image</label>
                <input type="file" id="image" name="image" accept="image/*" @change="pickImage">
                <input type="hidden" name="clear_image" :value="clearImage ? 1 : 0">
                <p class="hint">
                    <span x-show="usingPoster">Using the show poster.</span>
                    <span x-show="!usingPoster">Using a custom image.</span>
                    <template x-if="canReset">
                        <button type="button" class="btn-inline" @click="resetToPoster">Switch back to the poster</button>
                    </template>
                </p>
            </div>
            {{-- Disabled controls aren't submitted, and both focus sliders disable
                 themselves when the image can't be cropped on that axis. Carry the
                 values in hidden inputs so the required fields always arrive. --}}
            <input type="hidden" name="focal_x" :value="focalX">
            <input type="hidden" name="focal_y" :value="focalY">
            <div class="row">
                <div class="field">
                    <label for="focal_x">Focus &larr;&rarr;</label>
                    <input type="range" id="focal_x" min="0" max="100" x-model="focalX" :disabled="!canFocusX">
                </div>
                <div class="field">
                    <label for="focal_y">Focus &uarr;&darr;</label>
                    <input type="range" id="focal_y" min="0" max="100" x-model="focalY" :disabled="!canFocusY">
                </div>
            </div>
            <p class="hint">
                Chooses which part of the image stays in frame.
                <span x-show="canFocusX && !canFocusY">This image is wider than the card, so only the &larr;&rarr; slider applies.</span>
                <span x-show="canFocusY && !canFocusX">This image is taller than the card, so only the &uarr;&darr; slider applies.</span>
                <span x-show="!canFocusX && !canFocusY">This image fits the card exactly, so no cropping is needed.</span>
            </p>
        </div>

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
            <p class="og-status" x-show="ogMessage" x-text="ogMessage" role="status"></p>
        @endif
    </form>
</div>

<script>
    function cardBuilder() {
        return {
            quote: @json($quote),
            reviewLines: @json($reviewLines),
            position: @json($options['position']),
            textSize: @json($options['text_size']),
            focalX: @json($options['focal_x']),
            focalY: @json($options['focal_y']),
            attributionEnabled: @json($options['attribution_enabled']),
            attributionText: @json($options['attribution_text']),
            attributionSize: @json($options['attribution_size']),
            posterUrl: @json($posterUrl),
            savedShareUrl: @json($shareImageUrl),
            uploadedUrl: null,
            clearImage: false,
            downloading: false,
@if ($canSave)
            settingOg: false,
            ogMessage: '',
@endif
            imgAspect: null,
            format: 'feed',
            vw: window.innerWidth,

            init() {
                window.addEventListener('resize', () => this.vw = window.innerWidth);
            },

            // OpenGraph is 1200x630, the 1.91:1 Facebook/Twitter/Slack render at.
            get cardWidth() {
                return this.format === 'og' ? 1200 : 1080;
            },
            // Guard: 'og' is only selectable from a tab that admins alone are served.
            get cardHeight() {
                if (this.format === 'og') return 630;
                return this.format === 'feed' ? 1080 : 1920;
            },
            get previewScale() {
                const available = Math.max(240, Math.min(540, this.vw - 64));
                const byWidth = available / this.cardWidth;
                if (this.format === 'og') return Math.min(0.45, byWidth);
                return this.format === 'feed' ? Math.min(0.5, byWidth) : Math.min(0.3, byWidth);
            },
            get frameAspect() {
                return this.cardWidth / this.cardHeight;
            },
            // Dark plateau centered on the text, fading to transparent in both directions.
            // At the extremes the plateau runs off the card edge, leaving a single fade —
            // solid black at the bottom fading upward (or the reverse at the top).
            get scrimStyle() {
                const p = Number(this.position);
                return `background: linear-gradient(to bottom,`
                    + ` rgba(0,0,0,0) ${p - 38}%,`
                    + ` rgba(0,0,0,0.88) ${p - 12}%,`
                    + ` rgba(0,0,0,0.88) ${p + 12}%,`
                    + ` rgba(0,0,0,0) ${p + 38}%)`;
            },
            get bgUrl() {
                if (this.uploadedUrl) return this.uploadedUrl;
                if (!this.clearImage && this.savedShareUrl) return this.savedShareUrl;
                return this.posterUrl;
            },
            get usingPoster() {
                return this.bgUrl === this.posterUrl;
            },
            get canReset() {
                return !this.usingPoster && this.posterUrl;
            },
            // The focus sliders shift the image within the frame's crop, so each axis only
            // applies when the image overflows the frame along that axis.
            get canFocusX() {
                return this.imgAspect === null || this.imgAspect > this.frameAspect + 0.001;
            },
            get canFocusY() {
                return this.imgAspect === null || this.imgAspect < this.frameAspect - 0.001;
            },
            measureImage(img) {
                this.imgAspect = img.naturalHeight ? img.naturalWidth / img.naturalHeight : null;
            },
            pickImage(event) {
                const file = event.target.files[0];
                if (!file) return;
                if (this.uploadedUrl) URL.revokeObjectURL(this.uploadedUrl);
                this.uploadedUrl = URL.createObjectURL(file);
                this.clearImage = false;
            },
            resetToPoster() {
                if (this.uploadedUrl) URL.revokeObjectURL(this.uploadedUrl);
                this.uploadedUrl = null;
                this.clearImage = true;
                document.getElementById('image').value = '';
            },
            // Shared by the download button and the OpenGraph upload.
            async renderPng() {
                const options = {
                    width: this.cardWidth,
                    height: this.cardHeight,
                    pixelRatio: 1,
                    style: { transform: 'none' },
                    // The card only uses system fonts; skipping font embedding avoids
                    // SecurityErrors from stylesheets injected by browser extensions.
                    skipFonts: true,
                };
                return await this.withCaptureClone(async (node) => {
                    // html-to-image's first render can fail or come back incomplete in
                    // some engines — retry, then fall back to the Blob-URL route.
                    let result = null;
                    let lastError = null;
                    for (let attempt = 0; attempt < 2 && !result; attempt++) {
                        try {
                            result = await htmlToImage.toPng(node, options);
                        } catch (error) {
                            lastError = error;
                        }
                    }
                    if (!result) {
                        try {
                            result = await this.renderViaBlob(node, options);
                        } catch (error) {
                            lastError = error;
                        }
                    }
                    if (!result) throw lastError;
                    return result;
                });
            },
            reportRenderFailure(error) {
                console.error('Share card render failed:', error);
                const detail = (error && error.message) ? error.message : String(error);
                alert('Could not render the image: ' + detail + ' — check the browser console for details.');
            },
            async download() {
                this.downloading = true;
                try {
                    const link = document.createElement('a');
                    link.download = @json($entry->slug()) + '-' + this.format + '.png';
                    link.href = await this.renderPng();
                    link.click();
                } catch (error) {
                    this.reportRenderFailure(error);
                } finally {
                    this.downloading = false;
                }
            },
@if ($canSave)
            // Renders the card and posts it straight to the entry's OpenGraph image, so a
            // review's share preview can be an actual excerpt of the review.
            async setOgImage() {
                this.settingOg = true;
                this.ogMessage = '';
                try {
                    const dataUrl = await this.renderPng();
                    const blob = await (await fetch(dataUrl)).blob();

                    const body = new FormData();
                    body.append('_token', document.querySelector('input[name=_token]').value);
                    body.append('image', blob, @json($entry->slug()) + '-og.png');

                    const response = await fetch(@json($ogUrl), {
                        method: 'POST',
                        body,
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(payload.message || ('Upload failed (' + response.status + ')'));
                    }

                    this.ogMessage = 'Set as the OpenGraph image for this show.';
                } catch (error) {
                    console.error('Setting the OG image failed:', error);
                    this.ogMessage = 'Failed: ' + ((error && error.message) ? error.message : String(error));
                } finally {
                    this.settingOg = false;
                }
            },
@endif
            // Alpine's :src/:style/@load attribute names are invalid XML and corrupt
            // html-to-image's SVG serialization — and stripping them from the LIVE card
            // makes Alpine revert those bindings. So capture a detached clone instead:
            // Alpine's applied state (src, inline styles, text) survives cloneNode, and
            // the clone can be scrubbed freely. It's parked off-viewport (not hidden —
            // html-to-image reads computed styles) and removed afterward.
            async withCaptureClone(fn) {
                const clone = document.getElementById('card').cloneNode(true);
                clone.removeAttribute('id');
                [clone, ...clone.querySelectorAll('*')].forEach((el) => {
                    [...el.attributes].forEach((attr) => {
                        if (attr.name.startsWith(':') || attr.name.startsWith('@') || attr.name.startsWith('x-')) {
                            el.removeAttribute(attr.name);
                        }
                    });
                });
                const holder = document.createElement('div');
                holder.style.position = 'fixed';
                holder.style.left = '-12000px';
                holder.style.top = '0';
                holder.appendChild(clone);
                document.body.appendChild(holder);
                try {
                    return await fn(clone);
                } finally {
                    holder.remove();
                }
            },
            // Firefox refuses to load html-to-image's composite SVG as a data: URI,
            // but loads the identical SVG from a Blob URL — draw that onto a canvas.
            async renderViaBlob(card, options) {
                const svgDataUrl = await htmlToImage.toSvg(card, options);
                const svgText = decodeURIComponent(svgDataUrl.substring(svgDataUrl.indexOf(',') + 1));
                const blobUrl = URL.createObjectURL(new Blob([svgText], { type: 'image/svg+xml;charset=utf-8' }));
                try {
                    const image = await new Promise((resolve, reject) => {
                        const img = new Image();
                        img.onload = () => resolve(img);
                        img.onerror = reject;
                        img.src = blobUrl;
                    });
                    const canvas = document.createElement('canvas');
                    canvas.width = this.cardWidth;
                    canvas.height = this.cardHeight;
                    canvas.getContext('2d').drawImage(image, 0, 0, this.cardWidth, this.cardHeight);
                    return canvas.toDataURL('image/png');
                } finally {
                    URL.revokeObjectURL(blobUrl);
                }
            },
        };
    }
</script>
</body>
</html>
