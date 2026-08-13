{{--
    One 1080x1080 slide of an Instagram carousel.

    Rendered by App\Http\Controllers\OgCarouselController at /og-carousel/{slide} so it can
    be iterated on in a browser, and rasterised by `php artisan fringe:carousel`, which
    screenshots that same URL at 2x and downsamples — the same pipeline as /og-card, and the
    same look: teal doodle field, top-to-bottom scrim, Georgia headline. Slides come from
    storage/app/private/og/carousel.json; edit that and reload.

    Two slide types. `title` is the cover: copy on top, up to three posters fanned off the
    bottom edge (the square /og-card layout). `slot` is a timeslot: slot name as the
    headline, then a row per show — poster thumbnail, name, time · venue, optional note.

    Same constraints as the OG card: everything inline, system fonts only, image paths are
    site paths.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $headline }}</title>
    <style>
        :root {
            --teal-deep: #00777c;
            --teal-light: #a6d0cf;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body { width: 1080px; height: 1080px; overflow: hidden; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            background: var(--teal-deep);
            color: white;
            position: relative;
        }

        /* Drawn at its native 1024px — scaling the tile to cover crushes the doodles. */
        .doodles {
            position: absolute;
            inset: 0;
            background-image: url('/assets/fringe/fringe-doodles.svg');
            background-size: 1024px 1024px;
            background-position: center;
            opacity: 0.7;
        }

        .scrim {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(176deg, rgba(0, 38, 40, 0.93) 0%, rgba(0, 46, 48, 0.84) 42%, rgba(0, 84, 88, 0.34) 74%, rgba(0, 110, 114, 0.10) 100%);
        }

        /* The slot slides carry copy all the way down, so the scrim stays heavy throughout. */
        .slot .scrim {
            background:
                linear-gradient(176deg, rgba(0, 38, 40, 0.93) 0%, rgba(0, 44, 46, 0.86) 50%, rgba(0, 60, 63, 0.72) 100%);
        }

        .card {
            position: relative;
            width: 1080px;
            height: 1080px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            padding: 72px 72px 0;
            gap: 40px;
        }

        .eyebrow {
            font-size: 24px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            color: var(--teal-light);
            margin-bottom: 26px;
        }

        h1 {
            font-family: Georgia, 'Times New Roman', serif;
            font-weight: 700;
            line-height: 1.06;
            letter-spacing: -0.015em;
            font-size: {{ $headlineSize }}px;
            text-wrap: balance;
        }

        .subhead {
            margin-top: 32px;
            font-size: 34px;
            line-height: 1.36;
            color: rgba(255, 255, 255, 0.93);
            max-width: 24em;
        }

        .footnote {
            margin-top: 34px;
            font-size: 24px;
            font-weight: 500;
            color: var(--teal-light);
        }

        /* Cover fan, straight from the square /og-card: bottom-anchored, bleeding off the
           canvas, alternating tilt. */
        .art {
            align-self: center;
            flex: 1 1 auto;
            position: relative;
            width: 860px;
        }

        .art img {
            position: absolute;
            bottom: -72px;
            width: 520px;
            height: 520px;
            object-fit: cover;
            border-radius: 14px;
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.45);
            outline: 3px solid rgba(255, 255, 255, 0.14);
            outline-offset: -3px;
        }

        @foreach ($posters as $i => $poster)
            .art img:nth-child({{ $i + 1 }}) {
                left: {{ $i * 170 }}px;
                z-index: {{ $i + 1 }};
                transform: rotate({{ $i % 2 === 0 ? -4 : 5 }}deg);
            }
        @endforeach

        /* Outro: copy on top, the site banner beneath it — framed like the portrait on the
           OG cards (white edge, rounded, tilted) so it reads as a pinned-up picture rather
           than a page header. The banner is 1080x400; at 900px wide it keeps its whole
           gag legible with room for the tilt inside the canvas. */
        .outro .subhead {
            /* A \n in the JSON is a deliberate line break — the punchline gets its own line. */
            white-space: pre-line;
            margin-top: 0;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 52px;
            line-height: 1.28;
            font-weight: 700;
            letter-spacing: -0.01em;
            max-width: none;
            text-wrap: balance;
        }

        .outro .eyebrow { margin-bottom: 34px; }

        /* The banner has doodles baked into its own background, so the tile behind it just
           reads as visual noise — the scrim over plain teal keeps a quiet gradient instead. */
        .outro .doodles { display: none; }

        /* The punchline is its own beat: a paragraph of air above it, a size up, and the
           banner's highlighter yellow so it lands as the joke rather than more copy. */
        .outro .punchline {
            margin-top: 56px;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 64px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #fbd748;
        }

        .outro .banner {
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .outro .banner img {
            display: block;
            width: 900px;
            height: auto;
            background: white;
            border: 12px solid white;
            border-radius: 18px;
            box-shadow: 0 22px 52px rgba(0, 0, 0, 0.5);
            transform: rotate(-3deg);
        }

        /* Timeslot rows. The poster thumb keeps the fan cards' framing at row scale. */
        .shows {
            display: flex;
            flex-direction: column;
            gap: 44px;
            margin-top: 8px;
        }

        .show {
            display: flex;
            align-items: center;
            gap: 40px;
        }

        .show img {
            flex: 0 0 auto;
            width: 210px;
            height: 210px;
            object-fit: cover;
            border-radius: 14px;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.45);
            outline: 3px solid rgba(255, 255, 255, 0.14);
            outline-offset: -3px;
        }

        .show:nth-child(odd) img { transform: rotate(-3deg); }
        .show:nth-child(even) img { transform: rotate(3deg); }

        .show .name {
            font-family: Georgia, 'Times New Roman', serif;
            font-weight: 700;
            font-size: 42px;
            line-height: 1.12;
            letter-spacing: -0.01em;
            text-wrap: balance;
        }

        .show .where {
            margin-top: 14px;
            font-size: 27px;
            font-weight: 600;
            color: var(--teal-light);
        }

        .show .where .time { color: white; }

        .show .note {
            margin-top: 12px;
            font-size: 25px;
            line-height: 1.3;
            color: rgba(255, 255, 255, 0.93);
        }
    </style>
</head>
<body class="{{ $type }}">
    <div class="doodles"></div>
    <div class="scrim"></div>

    <div class="card">
        <div class="copy">
            @if ($eyebrow)
                <p class="eyebrow">{{ $eyebrow }}</p>
            @endif

            @if ($headline)
                <h1>{{ $headline }}</h1>
            @endif

            @if ($subhead)
                <p class="subhead">{{ $subhead }}</p>
            @endif

            @if ($punchline)
                <p class="punchline">{{ $punchline }}</p>
            @endif

            @if ($footnote)
                <p class="footnote">{{ $footnote }}</p>
            @endif
        </div>

        @if ($type === 'outro' && $banner)
            <div class="banner"><img src="{{ $banner }}" alt=""></div>
        @endif

        @if ($type === 'title' && $posters)
            <div class="art">
                @foreach ($posters as $poster)
                    <img src="{{ $poster }}" alt="">
                @endforeach
            </div>
        @endif

        @if ($shows)
            <div class="shows">
                @foreach ($shows as $show)
                    <div class="show">
                        <img src="{{ $show['poster'] }}" alt="">
                        <div>
                            <p class="name">{{ $show['title'] }}</p>
                            <p class="where"><span class="time">{{ $show['time'] }}</span> · {{ $show['venue'] }}</p>
                            @if ($show['note'] ?? null)
                                <p class="note">{{ $show['note'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
