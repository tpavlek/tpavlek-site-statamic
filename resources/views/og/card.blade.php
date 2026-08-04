{{--
    A 1200x630 OpenGraph card.

    Rendered by App\Http\Controllers\OgCardController at /og-card so it can be iterated on
    in a browser, and rasterised by `php artisan og:card`, which screenshots that same URL
    at 2x and downsamples. There is deliberately no second implementation: what you see at
    /og-card is byte-for-byte what gets saved.

    The look follows public/assets/og-social-review-generator.png, which was made by hand
    the first time — teal doodle field, teal-to-black scrim behind the text, Georgia
    headline, and one or more show cards tilted alongside the copy.

    Two formats. `og` is the 1.91:1 link preview and splits left-to-right: copy in the left
    column, art fanned into the right third. `square` is the Instagram feed post, where that
    split would leave both halves too narrow, so it stacks — copy on top, art beneath — and
    the scrim runs top-to-bottom to match.

    Everything is inline and self-contained. The rasteriser loads this over the local site,
    so referenced images must be site paths; system fonts only, because a webfont that has
    not loaded when Chrome takes the shot silently changes the whole layout.
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

        html, body { width: {{ $width }}px; height: {{ $height }}px; overflow: hidden; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            background: var(--teal-deep);
            color: white;
            position: relative;
        }

        /* The doodle tile is 1024px square and must be drawn at that size — scaling it to
           cover crushes the drawings into unreadable specks. Same rule as the Fringe pages. */
        .doodles {
            position: absolute;
            inset: 0;
            background-image: url('{{ $pattern }}');
            background-size: 1024px 1024px;
            background-position: center;
            opacity: 0.7;
        }

        /* The scrim is heaviest under the copy and clears off toward the artwork. Without it
           a doodle can run straight through a letterform. It follows the layout: sideways on
           the wide card, downward on the square one. */
        .scrim {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(100deg, rgba(0, 38, 40, 0.93) 0%, rgba(0, 48, 50, 0.78) 38%, rgba(0, 88, 92, 0.22) 72%, rgba(0, 110, 114, 0.06) 100%);
        }

        .square .scrim {
            background:
                linear-gradient(176deg, rgba(0, 38, 40, 0.93) 0%, rgba(0, 46, 48, 0.84) 42%, rgba(0, 84, 88, 0.34) 74%, rgba(0, 110, 114, 0.10) 100%);
        }

        .card {
            position: relative;
            width: {{ $width }}px;
            height: {{ $height }}px;
            display: flex;
            align-items: center;
            padding: 56px 60px;
            gap: 32px;
        }

        /* Stacked, and pushed to the top: the art hangs off the bottom edge rather than
           floating in the middle of a square with dead space under it. */
        .square .card {
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            padding: 72px 72px 0;
            gap: 40px;
        }

        .copy { flex: 1 1 auto; min-width: 0; }

        .square .copy { flex: 0 0 auto; }

        .eyebrow {
            font-size: 20px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            color: var(--teal-light);
            margin-bottom: 22px;
        }

        h1 {
            font-family: Georgia, 'Times New Roman', serif;
            font-weight: 700;
            line-height: 1.06;
            letter-spacing: -0.015em;
            /* Tiers rather than a fitting script: three sizes cover every headline worth
               putting on a card, and a headline that overflows the largest is too long. */
            font-size: {{ $headlineSize }}px;
            text-wrap: balance;
        }

        .subhead {
            margin-top: 24px;
            font-size: 24px;
            line-height: 1.36;
            color: rgba(255, 255, 255, 0.93);
            max-width: 19em;
        }

        .square .cta { margin-top: 36px; padding: 15px 32px; font-size: 27px; }
        .square .eyebrow { font-size: 24px; margin-bottom: 26px; }
        .square .subhead { margin-top: 32px; font-size: 32px; max-width: 24em; }
        .square .footnote { margin-top: 34px; font-size: 22px; }

        /* A card is a link, and a link should say what happens when you follow it. Bordered
           rather than filled so it reads as a label on the artwork, not a real button
           promising a tap target that doesn't exist. */
        .cta {
            display: inline-block;
            margin-top: 30px;
            padding: 12px 26px;
            border: 2px solid var(--teal-light);
            border-radius: 999px;
            font-size: 21px;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: white;
        }

        .footnote {
            margin-top: 22px;
            font-size: 18px;
            font-weight: 500;
            color: var(--teal-light);
        }

        /* Fanned show cards. Square art, so the stack is sized off the card height and the
           tilt alternates to read as a handful of them rather than a misaligned one. */
        /* margin-right, not margin-left: the copy is `flex: 1 1 auto`, so a negative left
           margin is simply handed back to the copy and the fan doesn't move. Pulling the
           right margin in is what pushes the fan past the padding, so a fourth card runs off
           the edge of the canvas instead of crowding the headline. */
        .art {
            flex: 0 0 auto;
            position: relative;
            width: {{ $fan['width'] }}px;
            margin-right: -{{ $fan['overhang'] }}px;
            height: 400px;
        }

        /* Fills whatever the copy leaves, and the cards hang off the bottom edge rather than
           floating with dead space beneath them — a square crops from the bottom in most
           feeds anyway, so there is nothing down there worth protecting. */
        .square .art {
            align-self: center;
            flex: 1 1 auto;
            width: {{ $squareFan['width'] }}px;
            /* Stacked and centred, so there is nothing to push against. */
            margin-right: 0;
            height: auto;
        }

        .square .art img {
            top: auto;
            bottom: -72px;
            width: 520px;
            height: 520px;
        }

        .art img {
            position: absolute;
            top: 50%;
            width: 300px;
            height: 300px;
            object-fit: cover;
            border-radius: 14px;
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.45);
            outline: 3px solid rgba(255, 255, 255, 0.14);
            outline-offset: -3px;
        }

        {{-- A photo reads as a photo, not as another piece of show art: white edge, a firmer
             tilt, and always on top of the fan. --}}
        .art img.portrait {
            /* White behind, so a cutout PNG reads as a photo rather than a teal-filled hole. */
            background: white;
            border: 10px solid white;
            outline: none;
            box-shadow: 0 22px 52px rgba(0, 0, 0, 0.5);
            object-position: {{ $portraitFocus }};
        }

        @foreach ($images as $i => $image)
            .art img:nth-child({{ $i + 1 }}) {
                left: {{ $i * $fan['step'] }}px;
                z-index: {{ $i + 1 }};
                transform: translateY(-50%) rotate({{ $i % 2 === 0 ? -4 : 5 }}deg);
            }

            {{-- Bottom-anchored, so the translateY that centres the wide card's fan has to go. --}}
            .square .art img:nth-child({{ $i + 1 }}) {
                left: {{ $i * $squareFan['step'] }}px;
                transform: rotate({{ $i % 2 === 0 ? -4 : 5 }}deg);
            }
        @endforeach

        @if ($portrait)
            @php($p = count($images))
            .art img:nth-child({{ $p + 1 }}) {
                left: {{ $p * $fan['step'] }}px;
                z-index: {{ $p + 1 }};
                transform: translateY(-50%) rotate({{ $p % 2 === 0 ? -4 : 5 }}deg);
            }

            .square .art img:nth-child({{ $p + 1 }}) {
                left: {{ $p * $squareFan['step'] }}px;
                transform: rotate({{ $p % 2 === 0 ? -4 : 5 }}deg);
            }
        @endif
    </style>
</head>
<body class="{{ $format }}">
    <div class="doodles"></div>
    <div class="scrim"></div>

    <div class="card">
        <div class="copy">
            @if ($eyebrow)
                <p class="eyebrow">{{ $eyebrow }}</p>
            @endif

            <h1>{{ $headline }}</h1>

            @if ($subhead)
                <p class="subhead">{{ $subhead }}</p>
            @endif

            @if ($cta)
                <p><span class="cta">{{ $cta }}</span></p>
            @endif

            @if ($footnote)
                <p class="footnote">{{ $footnote }}</p>
            @endif
        </div>

        @if ($images || $portrait)
            <div class="art">
                @foreach ($images as $image)
                    <img src="{{ $image }}" alt="">
                @endforeach

                @if ($portrait)
                    <img class="portrait" src="{{ $portrait }}" alt="">
                @endif
            </div>
        @endif
    </div>
</body>
</html>
