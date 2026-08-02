{{--
    Step two, and only for sources that list many reviews on one page (Fringe Reviews).

    Each option is its own form posting the review's id back to the same build endpoint, so
    the choice survives without any server-side state — the generator deliberately keeps
    none. The id comes from the source rather than the position in this list: a review
    posted between the two requests would shift the positions and hand the artist a
    different review than the one they clicked.
--}}
<div class="chooser">
    <div class="review-options">
        @foreach ($reviews as $review)
            <form method="POST" action="{{ $buildUrl }}" class="review-option">
                @csrf
                <input type="hidden" name="url" value="{{ $sourceUrl }}">
                <input type="hidden" name="review" value="{{ $review->reviewId }}">

                <div class="review-option-head">
                    <p class="review-who">{{ $review->reviewer ?: 'Anonymous' }}</p>
                    @if ($review->stars)
                        <p class="review-stars" aria-label="{{ (int) $review->stars }} out of 5 stars">
                            <span aria-hidden="true">{{ str_repeat('★', (int) $review->stars) }}</span>
                        </p>
                    @else
                        <p class="review-unrated">No rating</p>
                    @endif
                </div>

                @if ($review->reviewedAt)
                    <p class="review-when">{{ $review->reviewedAt }}</p>
                @endif

                <div class="review-body">
                    @foreach ($review->paragraphs as $paragraph)
                        <p>{{ implode(' ', $paragraph) }}</p>
                    @endforeach
                </div>

                <button type="submit" class="btn btn-secondary">Use this review &rarr;</button>
            </form>
        @endforeach
    </div>

    <p class="sources">
        Not the right show? <a href="{{ $backUrl }}">Start over</a> with a different link.
    </p>
</div>

<script>
    // Fade the bottom of a review only while there is still text below the fold. Whether one
    // is cut off can't be expressed in CSS — it needs the measurement — and getting it wrong
    // is conspicuous: the box is only as tall as its content, so an unconditional fade dims
    // the last line of every card and washes out a one-line review completely.
    (function () {
        var markClipped = function () {
            document.querySelectorAll('.review-body').forEach(function (body) {
                var below = body.scrollHeight - body.scrollTop - body.clientHeight;
                body.classList.toggle('is-clipped', below > 1);
            });
        };

        markClipped();
        window.addEventListener('resize', markClipped);
        // Scrolling to the end of a review should clear its fade.
        document.querySelectorAll('.review-body').forEach(function (body) {
            body.addEventListener('scroll', markClipped, { passive: true });
        });
    })();
</script>
