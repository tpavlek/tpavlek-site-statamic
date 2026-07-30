{{--
    The card itself. This exact node is what gets rasterized, so everything inside it must
    survive cloneNode with its applied styles — see withCaptureClone in _builder.

    Shared by the entry share-card page and the public generator. The only entry-only piece
    is the watchlist badge, which the generator has no concept of.
--}}
<div class="card" id="card" :style="cardStyle">
    <img class="bg" :src="bgUrl" x-show="bgUrl" :style="`object-position: ${focalX}% ${focalY}%`" @load="measureImage($event.target)" alt="">
    <div class="overlay" :style="scrimStyle">
        <div class="panel" :style="`top: ${position}%; transform: translateY(-${position}%)`">
            <div class="stars" x-show="showStars" x-text="starsText"></div>
            @if ($watchlist ?? false)
                <div class="watch-heading" x-show="!showStars">Show to watch</div>
            @endif
            <div class="quote" :style="`font-size: ${textSize}px`"><span class="quote-mark open">&ldquo;</span><span x-text="quote"></span><span class="quote-mark close">&rdquo;</span></div>
            <div class="attribution" x-show="attributionEnabled" :style="`font-size: ${attributionSize}px`" x-text="attributionText"></div>
        </div>
    </div>
</div>
