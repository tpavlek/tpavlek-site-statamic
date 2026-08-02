    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --teal: #008483;
            --teal-dark: #006c6b;
            --teal-wash: #eaf4f3;
            --ink: #1f2937;
            --ink-soft: #6b7280;
            --line: #e4e4e0;
            --paper: #fafaf8;
            --stage: #10201f;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--paper); color: var(--ink); }

        /* ---- Masthead ---- */
        .masthead { padding: 1.75rem 2rem 1.5rem; border-bottom: 1px solid var(--line); background: white; }
        .masthead .eyebrow { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--teal); margin-bottom: 0.4rem; }
        .masthead h1 { font-family: Georgia, 'Times New Roman', serif; font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
        .masthead .show-line { margin-top: 0.35rem; color: var(--ink-soft); font-size: 0.95rem; }
        .masthead .show-line .stars { color: var(--teal); letter-spacing: 2px; }
        .masthead .lede { margin-top: 0.6rem; font-size: 0.9rem; color: var(--ink-soft); max-width: 46rem; }

        .flash { background: var(--teal-wash); border: 1px solid var(--teal); color: var(--teal-dark); padding: 0.6rem 1rem; border-radius: 8px; margin: 1.25rem 2rem 0; font-size: 0.9rem; }
        .flash-error { background: #fdf2f2; border-color: #c53030; color: #9b2c2c; }
        .flash-error ul { margin: 0.35rem 0 0 1.1rem; }

        /* ---- Two-pane layout ---- */
        .builder { display: flex; gap: 2.5rem; padding: 2rem; align-items: flex-start; flex-wrap: wrap; }
        .stage-pane { flex: 1 1 380px; display: flex; flex-direction: column; align-items: center; min-width: 0; }
        .controls { flex: 1 1 320px; max-width: 460px; }

        /* ---- Preview stage ---- */
        .format-tabs { display: flex; gap: 0.4rem; background: white; border: 1px solid var(--line); border-radius: 999px; padding: 0.3rem; margin-bottom: 1.1rem; }
        .format-tabs button { border: none; background: none; font: inherit; font-size: 0.85rem; font-weight: 600; color: var(--ink-soft); padding: 0.4rem 1.1rem; border-radius: 999px; cursor: pointer; }
        .format-tabs button.active { background: var(--teal); color: white; }
        .stage { width: 100%; min-height: 620px; background: var(--stage); border-radius: 14px; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
        .preview-frame { overflow: hidden; border-radius: 4px; box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45); }
        .preview-scale { transform-origin: top left; }
        .stage-caption { margin-top: 0.8rem; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--ink-soft); }

        /* ---- Controls ---- */
        .group { background: white; border: 1px solid var(--line); border-radius: 12px; padding: 1.25rem 1.35rem; margin-bottom: 1.1rem; }
        .group-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--teal); margin-bottom: 0.9rem; }
        .field { margin-bottom: 1rem; }
        .field:last-child { margin-bottom: 0; }
        .field > label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--ink); margin-bottom: 0.35rem; }
        .text-input { width: 100%; padding: 0.55rem 0.8rem; border: 1px solid var(--line); border-radius: 8px; font: inherit; font-size: 0.95rem; background: white; }
        .group-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.9rem; }
        .group-head .group-title { margin-bottom: 0; }
        .switch { position: relative; display: inline-block; width: 2.5rem; height: 1.4rem; cursor: pointer; }
        .switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
        .switch-track { position: absolute; inset: 0; background: #cfcfc9; border-radius: 999px; transition: background 0.15s; pointer-events: none; }
        .switch-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: calc(1.4rem - 4px); height: calc(1.4rem - 4px); background: white; border-radius: 50%; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25); transition: transform 0.15s; }
        .switch input:checked + .switch-track { background: var(--teal); }
        .switch input:checked + .switch-track::after { transform: translateX(1.1rem); }
        .switch input:focus-visible + .switch-track { outline: 2px solid var(--teal); outline-offset: 2px; }
        .field .hint { font-size: 0.78rem; color: var(--ink-soft); margin-top: 0.3rem; line-height: 1.4; }
        textarea#quote { width: 100%; min-height: 7.5rem; padding: 0.7rem 0.8rem; border: 1px solid var(--line); border-radius: 8px; font-family: Georgia, 'Times New Roman', serif; font-size: 1.05rem; line-height: 1.45; resize: vertical; background: white; }
        .char-count { float: right; font-weight: 400; color: var(--ink-soft); font-size: 0.78rem; }
        input[type=range] { width: 100%; accent-color: var(--teal); }
        input[type=range]:disabled { opacity: 0.35; }
        .row { display: flex; gap: 1.1rem; }
        .row .field { flex: 1; }
        .stepper { display: flex; align-items: stretch; max-width: 10rem; }
        .stepper button { width: 2.4rem; border: 1px solid var(--line); background: var(--paper); font: inherit; font-size: 1.1rem; cursor: pointer; color: var(--ink); }
        .stepper button:first-child { border-radius: 8px 0 0 8px; }
        .stepper button:last-child { border-radius: 0 8px 8px 0; }
        .stepper input { width: 100%; text-align: center; border: 1px solid var(--line); border-left: none; border-right: none; font: inherit; font-size: 0.9rem; padding: 0.45rem 0; -moz-appearance: textfield; background: white; }
        .stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; }
        /* ---- Quote picker ---- */
        [x-cloak] { display: none !important; }
        .pick-link { display: inline-flex; align-items: center; gap: 0.4rem; font: inherit; font-size: 0.85rem; font-weight: 600; color: var(--teal); background: none; border: none; padding: 0; cursor: pointer; }
        .pick-link:hover { color: var(--teal-dark); }
        .pick-link .glyph { font-size: 1rem; line-height: 1; }
        .picker-backdrop { position: fixed; inset: 0; background: rgba(16, 32, 31, 0.55); display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 50; }
        .picker { background: white; border-radius: 14px; width: min(42rem, 100%); max-height: min(90vh, 46rem); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35); }
        .picker-head { padding: 1.1rem 1.35rem; border-bottom: 1px solid var(--line); display: flex; align-items: flex-start; gap: 1rem; }
        .picker-title { font-size: 1rem; font-weight: 700; }
        .picker-source { margin-top: 0.15rem; font-size: 0.82rem; color: var(--ink-soft); }
        .picker-x { margin-left: auto; border: none; background: none; cursor: pointer; font-size: 1.4rem; line-height: 1; color: var(--ink-soft); padding: 0 0.2rem; }
        .picker-x:hover { color: var(--ink); }
        .picker-prose { padding: 1.1rem 1.35rem 1.5rem; overflow-y: auto; flex: 1; font-family: Georgia, 'Times New Roman', serif; font-size: 1rem; line-height: 1.6; }
        .picker-prose p { margin-bottom: 0.9rem; }
        .picker-prose p:last-child { margin-bottom: 0; }
        .picker-prose ::selection { background: rgba(0, 132, 131, 0.22); }
        .sentence { cursor: pointer; border-radius: 3px; }
        .sentence:hover { background: var(--teal-wash); }
        .sentence.on { background: rgba(0, 132, 131, 0.2); box-shadow: 0 0 0 1px rgba(0, 132, 131, 0.28); }
        .picker-foot { border-top: 1px solid var(--line); background: var(--paper); padding: 0.9rem 1.35rem 1.1rem; display: flex; flex-direction: column; gap: 0.75rem; }
        /* pre-line so a cross-paragraph excerpt previews with the break the card will render. */
        .excerpt { font-family: Georgia, 'Times New Roman', serif; font-size: 0.95rem; line-height: 1.4; white-space: pre-line; background: white; border: 1px solid var(--line); border-radius: 8px; padding: 0.6rem 0.75rem; max-height: 7rem; overflow-y: auto; }
        .excerpt.empty { color: var(--ink-soft); font-style: italic; }
        .picker-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .excerpt-count { font-size: 0.78rem; color: var(--ink-soft); font-variant-numeric: tabular-nums; }
        .excerpt-count.over { color: #c53030; font-weight: 700; }
        .snap { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: var(--ink-soft); cursor: pointer; }
        .snap input { accent-color: var(--teal); margin: 0; }
        .picker-spacer { flex: 1; }
        .picker .btn { padding: 0.6rem 1.2rem; font-size: 0.9rem; }
        .picker .btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .picker .btn-primary:disabled:hover { background: var(--teal); }
        input[type=file] { width: 100%; font-size: 0.85rem; color: var(--ink-soft); }
        input[type=file]::file-selector-button { font: inherit; font-size: 0.85rem; font-weight: 600; color: var(--teal); background: var(--teal-wash); border: 1px solid var(--teal); border-radius: 8px; padding: 0.4rem 0.9rem; margin-right: 0.8rem; cursor: pointer; }

        /* ---- Actions ---- */
        .actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-top: 1.4rem; }
        .btn { padding: 0.7rem 1.4rem; border-radius: 8px; border: none; font: inherit; font-size: 0.95rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--teal); color: white; }
        .btn-primary:hover { background: var(--teal-dark); }
        .btn-secondary { background: white; color: var(--teal); border: 1px solid var(--teal); }
        .btn-secondary:hover { background: var(--teal-wash); }
        .btn-link { background: none; color: var(--ink-soft); font-weight: 400; padding: 0.7rem 0.5rem; }
        .btn-link:hover { color: var(--ink); }
        .og-status { margin-top: 0.7rem; font-size: 0.85rem; color: var(--teal-dark); }
        button.btn-inline { border: none; background: none; font: inherit; font-size: inherit; color: var(--teal); cursor: pointer; padding: 0; text-decoration: underline; }
        :focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }

        /* ---- The card itself: this exact node is what gets rasterized ---- */
        .card { position: relative; overflow: hidden; background: #111; }
        .card .bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .card .overlay { position: absolute; inset: 0; }
        .card .panel { position: absolute; left: 0; width: 100%; padding: 56px 60px; }
        .card .stars { font-size: 72px; color: white; letter-spacing: 6px; line-height: 1; margin-bottom: 28px; }
        .card .watch-heading { display: inline-block; font-size: 34px; font-weight: 700; color: #a6d0cf; text-transform: uppercase; letter-spacing: 5px; line-height: 1; background: rgba(166, 208, 207, 0.3); border: 3px solid #a6d0cf; border-radius: 12px; padding: 16px 30px 14px; margin-bottom: 32px; }
        .card .quote { font-family: Georgia, 'Times New Roman', serif; color: white; white-space: pre-line; line-height: 1.25; }
        .card .quote .quote-mark { font-size: 1.5em; line-height: 0; opacity: 0.55; position: relative; top: 0.22em; }
        .card .quote .quote-mark.open { margin-right: 0.08em; }
        .card .quote .quote-mark.close { margin-left: 0.08em; }
        .card .attribution { margin-top: 32px; font-size: 34px; color: rgba(255, 255, 255, 0.85); font-style: italic; }

        @media (max-width: 720px) {
            .masthead { padding: 1.25rem 1rem; }
            .builder { padding: 1rem; gap: 1.5rem; }
            .flash { margin: 1rem 1rem 0; }
            .stage { min-height: 0; }
            .controls { max-width: none; }
        }

        /* ---- Source chooser (generator only) ---- */
        .chooser { padding: 2rem; max-width: 52rem; }
        .chooser-options { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.25rem; }
        .chooser-card { flex: 1 1 18rem; text-align: left; background: white; border: 1px solid var(--line); border-radius: 12px; padding: 1.25rem 1.35rem; cursor: pointer; font: inherit; color: inherit; }
        .chooser-card:hover, .chooser-card:focus-visible { border-color: var(--teal); background: var(--teal-wash); }
        .chooser-card h2 { font-size: 1rem; font-weight: 700; margin-bottom: 0.3rem; }
        .chooser-card p { font-size: 0.88rem; color: var(--ink-soft); line-height: 1.45; }
        .url-form { margin-top: 1.5rem; background: white; border: 1px solid var(--line); border-radius: 12px; padding: 1.25rem 1.35rem; }
        .url-row { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .url-row input { flex: 1 1 20rem; padding: 0.6rem 0.8rem; border: 1px solid var(--line); border-radius: 8px; font: inherit; font-size: 0.95rem; }
        .sources { margin-top: 0.9rem; font-size: 0.82rem; color: var(--ink-soft); line-height: 1.5; }
        .sources strong { color: var(--ink); font-weight: 600; }
        .notice { margin-top: 1rem; border-radius: 8px; padding: 0.7rem 0.9rem; font-size: 0.88rem; }
        .notice-warn { background: #fffaf0; border: 1px solid #dd6b20; color: #9c4221; }

        /* ---- Review chooser (sources that list many reviews on one page) ---- */
        .review-options { display: grid; grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); gap: 1rem; margin-top: 0.5rem; }
        .review-option { display: flex; flex-direction: column; gap: 0.5rem; background: white; border: 1px solid var(--line); border-radius: 12px; padding: 1.1rem 1.2rem; }
        .review-option:hover { border-color: var(--teal); }
        .review-option-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.75rem; }
        .review-who { font-weight: 700; font-size: 0.95rem; }
        .review-stars { color: var(--teal); letter-spacing: 2px; font-size: 0.95rem; white-space: nowrap; }
        .review-unrated { font-size: 0.78rem; color: var(--ink-soft); white-space: nowrap; }
        .review-when { font-size: 0.78rem; color: var(--ink-soft); }
        /* Capped rather than truncated: the whole review is here to read, but a seven-paragraph
           one mustn't push every other option off the screen. Overlay scrollbars are why
           there's a fade instead of a styled scrollbar — on macOS the thumb is invisible
           until you're already scrolling, which is too late to be a hint. */
        .review-body { font-family: Georgia, 'Times New Roman', serif; font-size: 0.92rem; line-height: 1.5; max-height: 11rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.6rem; }
        /* Only where text is actually still below the fold. The box shrinks to its content —
           the button's auto margin takes up the slack — so its bottom edge sits on the last
           line whether or not anything is cut off. An unconditional mask therefore fades the
           final line of every card, and swallows a one-line review whole: at 22px tall, a
           1.4rem fade starts above the top of the element. markClipped() in the chooser keeps
           this class in step with the scroll position. */
        .review-body.is-clipped {
            -webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 1.4rem), transparent 100%);
            mask-image: linear-gradient(to bottom, #000 calc(100% - 1.4rem), transparent 100%); }
        .review-option .btn { margin-top: auto; align-self: flex-start; padding: 0.5rem 1rem; font-size: 0.88rem; }

        /* ---- Star colour swatches ---- */
        .swatches { display: flex; gap: 0.4rem; }
        .swatch { width: 2.4rem; height: 2.4rem; border-radius: 8px; border: 1px solid var(--line); cursor: pointer; font-size: 1.1rem; line-height: 1; display: flex; align-items: center; justify-content: center; }
        .swatch.on { border-color: var(--teal); box-shadow: 0 0 0 2px var(--teal-wash); }
        /* Checkerboard so a white star is visible on a white control panel. */
        .swatch--white { background: repeating-conic-gradient(#f1f1ef 0% 25%, white 0% 50%) 50% / 12px 12px; color: white; text-shadow: 0 0 2px rgba(0,0,0,0.5); }
        .swatch--black { background: white; color: #111; }
        .swatch--gold { background: white; color: #f5c518; }
        .swatch--teal { background: white; color: #008483; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

        /* ---- Stars control ---- */
        .star-picker { display: flex; gap: 0.25rem; }
        .star-picker button { border: 1px solid var(--line); background: white; border-radius: 8px; width: 2.4rem; height: 2.2rem; font: inherit; font-size: 0.9rem; cursor: pointer; color: var(--ink-soft); }
        .star-picker button.on { background: var(--teal); border-color: var(--teal); color: white; }
    </style>
