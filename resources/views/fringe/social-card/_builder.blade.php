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
            imageError: '',
            clearImage: false,
            downloading: false,
@if ($canSave)
            settingOg: false,
            ogMessage: '',
@endif
            imgAspect: null,
            format: 'feed',
            // A story is 1080x1920, so the 42px that suits a square post reads small in it.
            // Switching formats carries the size across only while it's still the format's
            // default — once someone has set their own, that's their decision, not ours.
            formatTextSizes: { feed: 42, portrait: 42, story: 60, og: 42 },
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
            // Moves the quote size to the new format's default, but only if it's still
            // sitting on the old one's — an untouched size is ours to adjust, a chosen one
            // isn't. Someone who set 55px in a story keeps 55px in a post.
            setFormat(next) {
                if (next !== this.format && this.textSize === this.formatTextSizes[this.format]) {
                    this.textSize = this.formatTextSizes[next];
                }

                this.format = next;
            },
            // OpenGraph is 1200x630, the 1.91:1 Facebook/Twitter/Slack render at. The rest
            // are the Instagram sizes: square, 4:5 portrait, and a full-height story. The
            // scale is the cap on the preview; a taller card gets a smaller one so the
            // whole thing still fits on screen next to the controls.
            formatSizes: {
                feed: { width: 1080, height: 1080, scale: 0.5 },
                portrait: { width: 1080, height: 1350, scale: 0.4 },
                story: { width: 1080, height: 1920, scale: 0.3 },
                og: { width: 1200, height: 630, scale: 0.45 },
            },
            get formatSize() {
                return this.formatSizes[this.format] || this.formatSizes.feed;
            },
            get cardWidth() {
                return this.formatSize.width;
            },
            get cardHeight() {
                return this.formatSize.height;
            },
            get previewScale() {
                const available = Math.max(240, Math.min(540, this.vw - 64));
                return Math.min(this.formatSize.scale, available / this.cardWidth);
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
            // A picked file becomes a downscaled JPEG data URL rather than a blob URL, which
            // is what the poster already is. That matters more than it looks: html-to-image
            // leaves a data: src alone, but for any other src it fetches the bytes and hands
            // the cloned <img> a brand new data URL, which then has to be decoded from
            // scratch inside the SVG the card is rasterized through. Safari drops the image
            // at that point — silently, so the card renders with everything except the photo
            // and the #111 card background shows through as a black square. Going in as a
            // data URL puts an upload on the same path as the poster, which works everywhere.
            //
            // Re-encoding is what fixes it, and it settles three other things on the way:
            // an iPhone HEIC becomes a JPEG every engine can draw, a 4000px photo stops
            // being a multi-megabyte payload inside the SVG, and EXIF goes away.
            async pickImage(event) {
                const file = event.target.files[0];
                if (!file) return;

                this.imageError = '';
                try {
                    this.uploadedUrl = await this.toCardImage(file);
                    this.clearImage = false;
                } catch (error) {
                    console.error('Could not read the picked image:', error);
                    // Whatever the browser can't decode it can't render either, so say so
                    // now rather than at download time, when it looks like a broken card.
                    this.imageError = 'This browser could not read that image. Try a JPEG or PNG.';
                    event.target.value = '';
                }
            },
            // 2160 is generous for a card that's at most 1080x1920 and crops to fill, and
            // small enough to keep the embedded copy to about a megabyte.
            async toCardImage(file, max = 2160) {
                const source = await this.decodeImage(file);
                const width = source.width || source.naturalWidth;
                const height = source.height || source.naturalHeight;
                if (!width || !height) throw new Error('Image decoded with no dimensions');

                const scale = Math.min(1, max / Math.max(width, height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(width * scale);
                canvas.height = Math.round(height * scale);

                const context = canvas.getContext('2d');
                // Transparency can't survive the card — .bg covers the frame and the card
                // sits on #111 — so flatten onto that here instead of leaving it to whatever
                // the JPEG encoder does with transparent pixels.
                context.fillStyle = '#111111';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(source, 0, 0, canvas.width, canvas.height);
                if (source.close) source.close();

                return canvas.toDataURL('image/jpeg', 0.92);
            },
            // createImageBitmap applies the EXIF rotation, which matters because re-encoding
            // drops the tag: a sideways phone photo would otherwise stay sideways. Older
            // Safari has createImageBitmap but not the orientation option, and honours EXIF
            // when drawing an <img> anyway, so that's the fallback.
            async decodeImage(file) {
                if (window.createImageBitmap) {
                    try {
                        return await createImageBitmap(file, { imageOrientation: 'from-image' });
                    } catch (error) {
                        // Falls through to the <img> route.
                    }
                }

                const url = URL.createObjectURL(file);
                try {
                    return await new Promise((resolve, reject) => {
                        const img = new Image();
                        img.onload = () => resolve(img);
                        img.onerror = () => reject(new Error('The browser could not decode ' + file.type));
                        img.src = url;
                    });
                } finally {
                    URL.revokeObjectURL(url);
                }
            },
            resetToPoster() {
                this.imageError = '';
                this.uploadedUrl = null;
                this.clearImage = true;
                const input = document.getElementById('image');
                if (input) input.value = '';
            },

            // ---- Rendering ----
            // Shared by the download button and the OpenGraph upload.
            //
            // The photo is drawn onto the canvas here rather than being left inside the card
            // html-to-image rasterizes. Everything it renders goes through an SVG
            // foreignObject, and a bitmap in there has to be decoded by the SVG image
            // context — which some engines, phone browsers especially, quietly decline to
            // do. The card then comes out with its text, stars and scrim intact and no
            // photo, which is what a couple of people have reported. Text and gradients in
            // a foreignObject are reliable; drawImage onto a canvas is reliable; a bitmap
            // inside a foreignObject is the one part that isn't, so it doesn't go there.
            //
            // The crop repeats what `object-fit: cover` and `object-position` do in the
            // preview, so the download frames the shot exactly as the sliders showed it.
            async renderPng() {
                const photo = this.bgUrl ? await this.loadPhoto(this.bgUrl) : null;
                // Without a photo the card is a flat wash the SVG renders perfectly well,
                // and if the photo won't load for compositing, leaving it in the card is a
                // better answer than dropping it.
                const overlay = await this.renderOverlay(!!photo);
                if (!photo) return overlay;

                const canvas = document.createElement('canvas');
                canvas.width = this.cardWidth;
                canvas.height = this.cardHeight;
                const context = canvas.getContext('2d');

                const scale = Math.max(this.cardWidth / photo.naturalWidth, this.cardHeight / photo.naturalHeight);
                const width = photo.naturalWidth * scale;
                const height = photo.naturalHeight * scale;
                // Percentage object-position places the overflow, not the image: at 0% the
                // left/top edge lines up, at 100% the right/bottom does.
                const x = (this.cardWidth - width) * (Number(this.focalX) / 100);
                const y = (this.cardHeight - height) * (Number(this.focalY) / 100);

                context.drawImage(photo, x, y, width, height);
                context.drawImage(await this.loadPhoto(overlay), 0, 0, this.cardWidth, this.cardHeight);

                return canvas.toDataURL('image/png');
            },
            // Resolves to null rather than throwing: a photo that can't be loaded for
            // compositing falls back to the old whole-card render.
            loadPhoto(url) {
                return new Promise((resolve) => {
                    const img = new Image();
                    // Same-origin assets and data URLs don't need this, but it keeps a
                    // CORS-enabled remote poster from tainting the canvas.
                    if (!url.startsWith('data:')) img.crossOrigin = 'anonymous';
                    img.onload = () => resolve(img);
                    img.onerror = () => resolve(null);
                    img.src = url;
                });
            },
            // The card as html-to-image sees it. With the photo composited separately the
            // background has to come out transparent, so the scrim fades onto the photo
            // instead of onto a black card.
            async renderOverlay(withoutPhoto) {
                const options = {
                    width: this.cardWidth,
                    height: this.cardHeight,
                    pixelRatio: 1,
                    style: { transform: 'none' },
                    // The card only uses system fonts; skipping font embedding avoids
                    // SecurityErrors from stylesheets injected by browser extensions.
                    skipFonts: true,
                };
                return await this.withCaptureClone(withoutPhoto, async (node) => {
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
            async withCaptureClone(withoutPhoto, fn) {
                const clone = document.getElementById('card').cloneNode(true);
                clone.removeAttribute('id');
                [clone, ...clone.querySelectorAll('*')].forEach((el) => {
                    [...el.attributes].forEach((attr) => {
                        if (attr.name.startsWith(':') || attr.name.startsWith('@') || attr.name.startsWith('x-')) {
                            el.removeAttribute(attr.name);
                        }
                    });
                });
                // The photo never goes through html-to-image: either it's composited onto
                // the canvas afterwards, or there isn't one and the <img> is sitting there
                // with an empty src, which html-to-image fetches and chokes on — that one
                // used to take the whole render down for a card with no image.
                clone.querySelector('.bg')?.remove();
                // Inline, because html-to-image copies the clone's computed styles onto its
                // own copy: a removed class would have no effect, this does. Only when
                // there's a photo to composite — otherwise the flat wash is the card.
                if (withoutPhoto) clone.style.background = 'transparent';
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
