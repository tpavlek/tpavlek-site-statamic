{{--
    The quote picker: the review as it reads, with the excerpt chosen by selecting text.

    Selection is the browser's own, not a bespoke chip-and-handle widget. Tapping a sentence
    selects it, dragging picks up as many as you want, and shift-click extends — all of it
    behaviour people already have, and it works with touch selection handles on mobile for
    free. `snapToSentences` then rounds whatever was highlighted out to whole sentences, so a
    sloppy drag still yields clean prose; turning it off keeps the highlight exactly, which is
    how you drop a clause. The one thing the native selection can't express — a
    non-contiguous pick — is cmd/ctrl-click, which toggles sentences in and out and marks
    each gap with an ellipsis.

    An over-long excerpt loads anyway ("Use anyway"): the length meter goes red as a warning,
    but the real editing surface is the textarea behind this dialog, so the picker shouldn't
    be the thing that blocks a quote someone intends to trim there.
--}}
<div class="picker-backdrop" x-show="pickerOpen" x-cloak
     @click="if ($event.target === $el) closePicker()"
     @keydown.escape.window="pickerOpen && closePicker()">
    <div class="picker" role="dialog" aria-modal="true" aria-labelledby="picker-title">
        <div class="picker-head">
            <div>
                <h2 class="picker-title" id="picker-title">Pick a line from the review</h2>
                <p class="picker-source">{{ $showLine ?: 'The review' }}</p>
            </div>
            <button type="button" class="picker-x" @click="closePicker" x-ref="pickerClose" aria-label="Close">&times;</button>
        </div>

        <div class="picker-prose" x-ref="pickerProse">
            <template x-for="(paragraph, pi) in reviewParagraphs" :key="pi">
                <p>
                    <template x-for="(sentence, si) in paragraph" :key="si">
                        <span class="sentence"
                              :data-sentence="sentenceIndex(pi, si)"
                              :class="selectedSentences.includes(sentenceIndex(pi, si)) && 'on'"
                              role="button"
                              tabindex="0"
                              :aria-pressed="selectedSentences.includes(sentenceIndex(pi, si))"
                              @click="selectSentence($event.currentTarget, $event)"
                              @keydown.enter.prevent="selectSentence($event.currentTarget, $event)"
                              @keydown.space.prevent="selectSentence($event.currentTarget, $event)"
                              x-text="sentence + (si < paragraph.length - 1 ? ' ' : '')"></span>
                    </template>
                </p>
            </template>
        </div>

        <div class="picker-foot">
            {{-- pre-line so an excerpt that crosses a paragraph shows the break here exactly
                 as the card will render it. --}}
            <div class="excerpt" :class="!excerpt && 'empty'"
                 x-text="excerpt || 'Tap a sentence, drag to select any part of the review, or ⌘/Ctrl-click to combine separate sentences.'"></div>
            <div class="picker-actions">
                <span class="excerpt-count" :class="excerptTooLong && 'over'"
                      x-text="excerpt.length + '/280'"></span>
                <label class="snap">
                    <input type="checkbox" x-model="snapToSentences" @change="readSelection">
                    Snap to whole sentences
                </label>
                <span class="picker-spacer"></span>
                <button type="button" class="btn btn-link" @click="closePicker">Cancel</button>
                <button type="button" class="btn btn-primary"
                        :disabled="!excerpt"
                        :title="excerptTooLong ? 'Over 280 characters — trim it in the editor after loading' : null"
                        @click="useExcerpt"
                        x-text="excerptTooLong ? 'Use anyway' : 'Use this excerpt'"></button>
            </div>
        </div>
    </div>
</div>
