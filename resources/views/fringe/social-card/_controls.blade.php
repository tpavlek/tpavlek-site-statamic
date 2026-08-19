{{--
    The control panel, shared by the entry share-card page and the public generator.

    $starsEditable — the generator lets the user set the rating; on an entry it comes from
                     the review and the switch only hides it.
    $canSave       — the entry page posts these as a form; the generator has no server state,
                     so the name attributes are inert there.
--}}
<div class="group">
    <p class="group-title">Quote</p>
    <div class="field">
        <label for="quote">Your favourite line from the review <span class="char-count"><span x-text="quote.length"></span>/280</span></label>
        <textarea id="quote" name="quote" maxlength="280" x-model="quote"></textarea>
        <p class="hint">Press Return to set your own line breaks &mdash; they appear on the card exactly as typed.</p>
    </div>
    {{-- One link rather than the review itself. The quote already starts on the review's
         opening line, so most people never need to open this. --}}
    <div class="field" x-show="reviewParagraphs.length">
        <button type="button" class="pick-link" @click="openPicker">
            <span class="glyph" aria-hidden="true">&#9776;</span>
            Pick a different line from the review
        </button>
    </div>
</div>

<div class="group">
    <div class="group-head">
        <p class="group-title">Star rating</p>
        <label class="switch">
            <input type="checkbox" x-model="starsEnabled" aria-label="Show the star rating on the card">
            <span class="switch-track" aria-hidden="true"></span>
        </label>
    </div>
    <input type="hidden" name="stars_enabled" :value="starsEnabled ? 1 : 0">
    <input type="hidden" name="stars_colour" :value="starsColour">
    <input type="hidden" name="stars_size" :value="starsSize">
    <div class="field" x-show="starsEnabled">
        <label>Star colour</label>
        <div class="swatches" role="radiogroup" aria-label="Star colour">
            @foreach (['white' => 'White', 'black' => 'Black', 'gold' => 'Gold', 'teal' => 'Teal'] as $key => $label)
                <button type="button" class="swatch swatch--{{ $key }}"
                        :class="starsColour === '{{ $key }}' && 'on'"
                        :aria-checked="starsColour === '{{ $key }}'"
                        role="radio"
                        @click="starsColour = '{{ $key }}'"
                        title="{{ $label }}"><span aria-hidden="true">★</span><span class="sr-only">{{ $label }}</span></button>
            @endforeach
        </div>
    </div>
    {{-- ±4 where the text steppers use ±2: at 72px a two-pixel nudge is invisible. --}}
    <div class="field" x-show="starsEnabled">
        <label for="stars_size">Star size</label>
        <div class="stepper">
            <button type="button" @click="starsSize = Math.max(24, starsSize - 4)" aria-label="Smaller stars">&minus;</button>
            <input type="number" id="stars_size" min="24" max="150" x-model.number="starsSize" aria-label="Star size in pixels">
            <button type="button" @click="starsSize = Math.min(150, starsSize + 4)" aria-label="Larger stars">+</button>
        </div>
    </div>
    @if ($starsEditable ?? false)
        <div x-show="starsEnabled">
            <div class="field">
                <label for="stars_value">Rating out of 5</label>
                <div class="stepper">
                    <button type="button" @click="starsValue = Math.max(0, starsValue - 0.5)" aria-label="Lower rating">&minus;</button>
                    <input type="number" id="stars_value" min="0" max="5" step="0.5" x-model.number="starsValue" aria-label="Rating out of five">
                    <button type="button" @click="starsValue = Math.min(5, starsValue + 0.5)" aria-label="Higher rating">+</button>
                </div>
                <p class="hint">Half stars are allowed. Shown on the card as <span x-text="starsText || 'nothing'"></span>.</p>
            </div>
        </div>
        <p class="hint" x-show="!starsEnabled">No rating on the card &mdash; the right choice when the review didn't give one.</p>
    @else
        <p class="hint" x-show="starsEnabled && starsText" x-text="'Showing ' + starsText + ' from the review.'"></p>
        <p class="hint" x-show="starsEnabled && !starsText">This review has no star rating, so nothing is shown.</p>
        <p class="hint" x-show="!starsEnabled">The rating is hidden from the card.</p>
    @endif
</div>

<div class="group">
    <div class="group-head">
        <p class="group-title">Show title</p>
        <label class="switch">
            <input type="checkbox" x-model="titleEnabled" aria-label="Show the show's title on the card">
            <span class="switch-track" aria-hidden="true"></span>
        </label>
    </div>
    <input type="hidden" name="title_enabled" :value="titleEnabled ? 1 : 0">
    <input type="hidden" name="title_text" :value="titleText">
    <input type="hidden" name="title_size" :value="titleSize">
    <div x-show="titleEnabled">
        <div class="field">
            <label for="title_text">Title text</label>
            <input type="text" id="title_text" class="text-input" maxlength="120" x-model="titleText">
        </div>
        <div class="field">
            <label for="title_size">Text size</label>
            <div class="stepper">
                <button type="button" @click="titleSize = Math.max(20, titleSize - 2)" aria-label="Smaller title text">&minus;</button>
                <input type="number" id="title_size" min="20" max="100" x-model.number="titleSize" aria-label="Title text size in pixels">
                <button type="button" @click="titleSize = Math.min(100, titleSize + 2)" aria-label="Larger title text">+</button>
            </div>
        </div>
    </div>
    <p class="hint" x-show="!titleEnabled">The show's title is left off the card.</p>
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
        <p class="hint hint-error" x-show="imageError" x-text="imageError" role="status"></p>
        <p class="hint">
            <span x-show="!bgUrl">No image &mdash; the card uses a solid colour.</span>
            <span x-show="bgUrl && usingPoster">{{ $posterLabel ?? 'Using the show poster.' }}</span>
            <span x-show="bgUrl && !usingPoster">Using a custom image.</span>
            <template x-if="canReset">
                <button type="button" class="btn-inline" @click="resetToPoster">{{ $resetLabel ?? 'Switch back to the poster' }}</button>
            </template>
        </p>
    </div>
    {{-- Disabled controls aren't submitted, and both focus sliders disable themselves when
         the image can't be cropped on that axis. Carry the values in hidden inputs so the
         required fields always arrive. --}}
    <input type="hidden" name="focal_x" :value="focalX">
    <input type="hidden" name="focal_y" :value="focalY">
    <input type="hidden" name="zoom" :value="zoom">
    <div class="field" x-show="bgUrl">
        <label for="zoom">Zoom</label>
        <input type="range" id="zoom" min="100" max="300" step="5" x-model.number="zoom" aria-label="Zoom, 100 percent fills the card, 300 percent is three times closer">
        <p class="hint">Zooming in past the frame lets you drag the image to reframe it in any direction.</p>
    </div>
    <div class="row" x-show="bgUrl">
        <div class="field">
            <label for="focal_x">Focus &larr;&rarr;</label>
            <input type="range" id="focal_x" min="0" max="100" x-model="focalX" :disabled="!canFocusX">
        </div>
        <div class="field">
            <label for="focal_y">Focus &uarr;&darr;</label>
            <input type="range" id="focal_y" min="0" max="100" x-model="focalY" :disabled="!canFocusY">
        </div>
    </div>
    <p class="hint" x-show="bgUrl">
        Chooses which part of the image stays in frame.
        <span x-show="canFocusX && !canFocusY">This image is wider than the card, so only the &larr;&rarr; slider applies.</span>
        <span x-show="canFocusY && !canFocusX">This image is taller than the card, so only the &uarr;&darr; slider applies.</span>
        <span x-show="!canFocusX && !canFocusY">This image fits the card exactly, so no cropping is needed.</span>
    </p>
</div>
