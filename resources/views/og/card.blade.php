{{--
    A 1200x630 OpenGraph card.

    Rendered by App\Http\Controllers\OgCardController at /og-card so it can be iterated on
    in a browser, and rasterised by `php artisan og:card`, which screenshots that same URL
    at 2x and downsamples. There is deliberately no second implementation: what you see at
    /og-card is byte-for-byte what gets saved.

    The look follows public/assets/og-social-review-generator.png, which was made by hand
    the first time — teal doodle field, teal-to-black scrim behind the text, Georgia
    headline, and one or more show cards tilted into the right third.

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

        html, body { width: 1200px; height: 630px; overflow: hidden; }

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

        /* Text sits on the left, so the scrim is heaviest there and clears off to the right
           where the artwork is. Without it a doodle can run straight through a letterform. */
        .scrim {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(100deg, rgba(0, 38, 40, 0.93) 0%, rgba(0, 48, 50, 0.78) 38%, rgba(0, 88, 92, 0.22) 72%, rgba(0, 110, 114, 0.06) 100%);
        }

        .card {
            position: relative;
            width: 1200px;
            height: 630px;
            display: flex;
            align-items: center;
            padding: 56px 60px;
            gap: 32px;
        }

        .copy { flex: 1 1 auto; min-width: 0; }

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

        .footnote {
            margin-top: 30px;
            font-size: 18px;
            font-weight: 500;
            color: var(--teal-light);
        }

        /* Fanned show cards. Square art, so the stack is sized off the card height and the
           tilt alternates to read as a handful of them rather than a misaligned one. */
        .art {
            flex: 0 0 auto;
            position: relative;
            width: {{ $artWidth }}px;
            height: 400px;
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

        @foreach ($images as $i => $image)
            .art img:nth-child({{ $i + 1 }}) {
                left: {{ $i * 112 }}px;
                z-index: {{ $i + 1 }};
                transform: translateY(-50%) rotate({{ $i % 2 === 0 ? -4 : 5 }}deg);
            }
        @endforeach
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

            <h1>{{ $headline }}</h1>

            @if ($subhead)
                <p class="subhead">{{ $subhead }}</p>
            @endif

            @if ($footnote)
                <p class="footnote">{{ $footnote }}</p>
            @endif
        </div>

        @if ($images)
            <div class="art">
                @foreach ($images as $image)
                    <img src="{{ $image }}" alt="">
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
