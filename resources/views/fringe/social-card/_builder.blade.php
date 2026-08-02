{{--
    The Alpine component behind the card. Shared by the entry share-card page and the public
    generator; everything specific to one of them arrives through the config object rather
    than through @if blocks, so there is one implementation of the rendering pipeline.
--}}
<script>
    function cardBuilder(config) {
        return {
            quote: config.quote,
            // Sentences grouped by paragraph. The picker needs the grouping so an excerpt
            // that crosses a paragraph gets its line break back.
            reviewParagraphs: config.reviewParagraphs,
            pickerOpen: false,
            excerpt: '',
            selectedSentences: [],
            snapToSentences: true,
            position: config.position,
            textSize: config.textSize,
            focalX: config.focalX,
            focalY: config.focalY,
            starsEnabled: config.starsEnabled,
            starsColour: config.starsColour,
            // Fixed on an entry (it comes from the review); driven by starsValue in the
            // generator, where the user sets the rating themselves.
            starsFixedText: config.starsFixedText,
            starsValue: config.starsValue,
            attributionEnabled: config.attributionEnabled,
            attributionText: config.attributionText,
            attributionSize: config.attributionSize,
            posterUrl: config.posterUrl,
            savedShareUrl: config.savedShareUrl,
            downloadName: config.downloadName,
            ogUrl: config.ogUrl,
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
            // Rendered height of the text panel in card pixels. Drives the scrim, so the
            // dark band grows with the quote instead of being a fixed slice of the card.
            panelHeight: 0,

            init() {
                window.addEventListener('resize', () => this.vw = window.innerWidth);

                // selectionchange is the only event that catches every way a selection can
                // change — drag, shift-click, double-click, touch handles, keyboard.
                document.addEventListener('selectionchange', () => {
                    if (this.pickerOpen) this.readSelection();
                });

                // offsetHeight is layout size, unaffected by the CSS transform that scales
                // the preview, so this is the true height at 1080px wide.
                const panel = this.$refs.panel;
                if (panel) {
                    const measure = () => this.panelHeight = panel.offsetHeight;
                    measure();
                    new ResizeObserver(measure).observe(panel);
                }
            },

            // ---- Quote picker ----
            // Flat index for a sentence, so a selection spanning paragraphs is still one
            // contiguous run of numbers.
            sentenceIndex(pi, si) {
                let n = 0;
                for (let i = 0; i < pi; i++) n += this.reviewParagraphs[i].length;
                return n + si;
            },
            get flatSentences() {
                const out = [];
                this.reviewParagraphs.forEach((paragraph, pi) => {
                    paragraph.forEach((text) => out.push({ text, paragraph: pi }));
                });
                return out;
            },
            get excerptTooLong() {
                return this.excerpt.length > 280;
            },

            openPicker() {
                this.pickerOpen = true;
                window.getSelection().removeAllRanges();
                this.excerpt = '';
                this.selectedSentences = [];
                this.$nextTick(() => this.$refs.pickerClose?.focus());
            },
            closePicker() {
                this.pickerOpen = false;
                window.getSelection().removeAllRanges();
            },
            useExcerpt() {
                this.quote = this.excerpt;
                this.closePicker();
            },

            // A plain tap selects the whole sentence. A tap that lands at the end of a real
            // drag would otherwise collapse it back to one sentence, so leave those alone —
            // and let shift-click through to the browser, which extends the selection itself.
            selectSentence(el, event) {
                if (event.shiftKey) return;

                const selection = window.getSelection();
                if (selection && !selection.isCollapsed && selection.toString().trim().length > 1) {
                    return this.readSelection();
                }

                const range = document.createRange();
                range.selectNodeContents(el);
                selection.removeAllRanges();
                selection.addRange(range);
                this.readSelection();
            },

            readSelection() {
                const prose = this.$refs.pickerProse;
                const selection = window.getSelection();

                if (!prose || !selection || !selection.rangeCount || selection.isCollapsed) {
                    this.excerpt = '';
                    this.selectedSentences = [];
                    return;
                }

                const range = selection.getRangeAt(0);
                if (!prose.contains(range.commonAncestorContainer)) return;

                const hits = [...prose.querySelectorAll('[data-sentence]')]
                    .filter((el) => this.rangeCovers(range, el))
                    .map((el) => Number(el.dataset.sentence));

                if (!hits.length) {
                    this.excerpt = '';
                    this.selectedSentences = [];
                    return;
                }

                if (!this.snapToSentences) {
                    this.selectedSentences = [];
                    this.excerpt = selection.toString().replace(/[ \t]+/g, ' ').trim();
                    return;
                }

                // Fill the run: a drag that clips the last sentence still takes all of it.
                const first = Math.min(...hits);
                const last = Math.max(...hits);
                this.selectedSentences = Array.from({ length: last - first + 1 }, (_, i) => first + i);
                this.excerpt = this.joinSentences(first, last);
            },

            // Sentences within a paragraph join with a space; a new paragraph starts a new
            // line. The card renders the quote with white-space: pre-line, so the break
            // survives all the way onto the image.
            joinSentences(first, last) {
                const sentences = this.flatSentences;
                let out = '';

                for (let i = first; i <= last; i++) {
                    if (i > first) {
                        out += sentences[i].paragraph === sentences[i - 1].paragraph ? ' ' : '\n\n';
                    }
                    out += sentences[i].text;
                }

                return out;
            },

            // Strict overlap: a selection that merely ends where a sentence begins doesn't
            // touch it. intersectsNode() counts that as a hit and drags in a spare sentence.
            rangeCovers(range, el) {
                const bounds = document.createRange();
                bounds.selectNodeContents(el);
                return range.compareBoundaryPoints(Range.END_TO_START, bounds) < 0
                    && range.compareBoundaryPoints(Range.START_TO_END, bounds) > 0;
            },

            // ---- Stars ----
            get starsText() {
                if (this.starsFixedText !== null) return this.starsFixedText;
                const n = Number(this.starsValue) || 0;
                if (n <= 0) return '';
                // Matches how the site renders a rating: full stars, then a half, and empty
                // stars dropped entirely so the card reads ★★★★ rather than ★★★★☆.
                return '★'.repeat(Math.floor(n)) + (n % 1 >= 0.5 ? '½' : '');
            },
            get showStars() {
                return this.starsEnabled && !!this.starsText;
            },
            // Black is included deliberately — it's the right choice over a pale image, and
            // the live preview shows immediately when it isn't.
            get starsColourValue() {
                return {
                    white: '#ffffff',
                    black: '#111111',
                    gold: '#f5c518',
                    teal: '#008483',
                }[this.starsColour] || '#ffffff';
            },
            // A card can be stars-only; empty quotation marks around nothing look like a bug.
            get hasQuote() {
                return (this.quote || '').trim().length > 0;
            },

            // ---- Format ----
            // OpenGraph is 1200x630, the 1.91:1 Facebook/Twitter/Slack render at.
            get cardWidth() {
                return this.format === 'og' ? 1200 : 1080;
            },
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

            // ---- Background ----
            get bgUrl() {
                if (this.uploadedUrl) return this.uploadedUrl;
                if (!this.clearImage && this.savedShareUrl) return this.savedShareUrl;
                return this.posterUrl;
            },
            // With no image the card falls back to a flat brand wash, so a review from a
            // source that has no usable photo still produces something worth posting.
            get cardStyle() {
                const size = `width: ${this.cardWidth}px; height: ${this.cardHeight}px;`;
                return this.bgUrl
                    ? size + ' background: #111;'
                    : size + ' background: linear-gradient(155deg, #0d3d3c 0%, #08211f 55%, #050d0d 100%);';
            },
            // A dark band behind the text, fading out above and below. It used to be a fixed
            // slice of the card centred on `position`, which meant a long quote — or one with
            // manual line breaks — overflowed the dark part and became unreadable against the
            // photo, worst at the top and bottom positions. Now it's derived from where the
            // panel actually sits and how tall it actually is, so it always covers the text.
            //
            // The panel is positioned `top: p%` with `translateY(-p%)` of its own height, so
            // its top edge is at (p/100) * (cardHeight - panelHeight). At the extremes the
            // band runs off the card edge, leaving a single fade, which is what you want.
            //
            // Pointless without a photo underneath, so it's skipped on the flat background.
            get scrimStyle() {
                if (!this.bgUrl) return '';

                const height = this.cardHeight;
                // Before the observer has reported, assume a typical panel rather than none.
                const panel = this.panelHeight || height * 0.24;
                const pct = (px) => (px / height) * 100;

                const top = (Number(this.position) / 100) * (height - panel);
                const solidFrom = pct(top - 40);
                const solidTo = pct(top + panel + 40);
                const fade = pct(260);

                return `background: linear-gradient(to bottom,`
                    + ` rgba(0,0,0,0) ${solidFrom - fade}%,`
                    + ` rgba(0,0,0,0.88) ${solidFrom}%,`
                    + ` rgba(0,0,0,0.88) ${solidTo}%,`
                    + ` rgba(0,0,0,0) ${solidTo + fade}%)`;
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
                const input = document.getElementById('image');
                if (input) input.value = '';
            },

            // ---- Rendering ----
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
                    link.download = this.downloadName + '-' + this.format + '.png';
                    link.href = await this.renderPng();
                    link.click();
                } catch (error) {
                    this.reportRenderFailure(error);
                } finally {
                    this.downloading = false;
                }
            },
@if ($canSave)
            {{-- Admin-only, and gated at render time rather than just disabled: OgImageTest
                 asserts none of this reaches an anonymous visitor. --}}
            // Renders the card and posts it straight to the entry's OpenGraph image, so a
            // review's share preview can be an actual excerpt.
            async setOgImage() {
                this.settingOg = true;
                this.ogMessage = '';
                try {
                    const dataUrl = await this.renderPng();
                    const blob = await (await fetch(dataUrl)).blob();

                    const body = new FormData();
                    body.append('_token', document.querySelector('input[name=_token]').value);
                    body.append('image', blob, this.downloadName + '-og.png');

                    const response = await fetch(this.ogUrl, {
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
