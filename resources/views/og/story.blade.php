{{--
    A 1080x1920 vertical thumbnail for a social media video — one per festival day.

    Rendered by App\Http\Controllers\OgStoryController at /og-story so it can be iterated
    on in a browser, and rasterised by `php artisan fringe:video-thumb`, which screenshots
    that same URL at 2x and downsamples — the same pipeline as /og-card and /og-carousel,
    and the same look: teal doodle field, top-to-bottom scrim, Georgia headline. Styling is
    copied, not shared, for the same reason as the carousel: a template change to one card
    format shouldn't ripple through the others.

    Layout, top to bottom: eyebrow, the big FRINGE headline with the day in highlighter
    yellow, the photo of Troy framed like a pinned-up picture (the portrait treatment from
    the OG cards), then one row per show covered in the video — title plus the same
    ★★★★½-style label the site itself renders.

    Same constraints as the other cards: everything inline, system fonts only, image paths
    are site paths.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $headline }} ({{ $day }})</title>
    <style>
        :root {
            --teal-deep: #00777c;
            --teal-light: #a6d0cf;
            --star-yellow: #fbd748;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body { width: 1080px; height: 1920px; overflow: hidden; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            background: var(--teal-deep);
            color: white;
            position: relative;
        }

        /* Drawn at its native 1024px — scaling the tile to cover crushes the doodles. The
           canvas is taller than the tile, so it repeats vertically. */
        .doodles {
            position: absolute;
            inset: 0;
            background-image: url('/assets/fringe/fringe-doodles.svg');
            background-size: 1024px 1024px;
            background-position: center top;
            opacity: 0.7;
        }

        /* Heavy at both ends: copy sits at the top, the show list at the bottom, and the
           photo carries the middle on its own. */
        .scrim {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(178deg,
                    rgba(0, 38, 40, 0.93) 0%,
                    rgba(0, 46, 48, 0.80) 26%,
                    rgba(0, 70, 74, 0.42) 52%,
                    rgba(0, 44, 46, 0.82) 78%,
                    rgba(0, 34, 36, 0.94) 100%);
        }

        .card {
            position: relative;
            width: 1080px;
            height: 1920px;
            display: flex;
            flex-direction: column;
            padding: 84px 80px 76px;
        }

        .eyebrow {
            font-size: 30px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            color: var(--teal-light);
            margin-bottom: 30px;
        }

        h1 {
            font-family: Georgia, 'Times New Roman', serif;
            font-weight: 700;
            line-height: 0.96;
            letter-spacing: -0.015em;
            font-size: 186px;
        }

        /* The day is part of the title but not the brand — a size down and the highlighter
           yellow, so FRINGE stays the monument and the day reads as the episode number. */
        h1 .day {
            display: block;
            margin-top: 18px;
            font-size: 96px;
            letter-spacing: -0.01em;
            color: var(--star-yellow);
        }

        /* The photo, framed like the portrait on the OG cards: white edge, rounded,
           tilted, so it reads as a pinned-up picture rather than a background. Flexes to
           absorb whatever height the show list doesn't need. */
        .photo {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 48px 0 56px;
        }

        .photo img {
            width: 900px;
            height: 100%;
            max-height: 880px;
            object-fit: cover;
            object-position: {{ $focus }};
            background: white;
            border: 14px solid white;
            border-radius: 18px;
            box-shadow: 0 24px 56px rgba(0, 0, 0, 0.5);
            transform: rotate(-2deg);
        }

        .shows {
            display: flex;
            flex-direction: column;
            gap: 46px;
        }

        .show .name {
            font-family: Georgia, 'Times New Roman', serif;
            font-weight: 700;
            font-size: 54px;
            line-height: 1.12;
            letter-spacing: -0.01em;
            text-wrap: balance;
        }

        .show .stars {
            margin-top: 12px;
            font-size: 46px;
            letter-spacing: 0.06em;
            color: var(--star-yellow);
            white-space: nowrap;
        }

        .footnote {
            margin-top: 52px;
            font-size: 28px;
            font-weight: 500;
            color: var(--teal-light);
        }
    </style>
</head>
<body>
    <div class="doodles"></div>
    <div class="scrim"></div>

    <div class="card">
        <div class="copy">
            @if ($eyebrow)
                <p class="eyebrow">{{ $eyebrow }}</p>
            @endif

            <h1>{{ $headline }} <span class="day">({{ $day }})</span></h1>
        </div>

        <div class="photo">
            <img src="{{ $photo }}" alt="">
        </div>

        @if ($shows)
            <div class="shows">
                @foreach ($shows as $show)
                    <div class="show">
                        <p class="name">{{ $show['title'] }}</p>
                        @if ($show['stars'])
                            <p class="stars">{{ $show['stars'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($footnote)
            <p class="footnote">{{ $footnote }}</p>
        @endif
    </div>
</body>
</html>
