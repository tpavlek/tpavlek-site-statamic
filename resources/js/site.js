// Alpine.js handles the show/hide of the mobile nav
import Alpine from 'alpinejs'

// Per-show refresh on the sold-out report (admin only), stepped so no single request has to
// survive a whole show's scrape: `start` plans the run and returns the performance ids that
// need scraping, then one `performance` call each (sequential, so the scraping stays
// single-threaded), then `finish` returns freshly rendered showtimes + header tags to swap in.
// `progress` narrates the loop ("refreshing 3 of 9") beside the button; `error` is a short
// human message ('' when fine), and the WAF-block case says how far it got before the ticket
// site cut us off — those performances are already saved.
Alpine.data('showRefresh', (eventId) => ({
    loading: false,
    error: '',
    progress: '',

    async post(path, body) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content

        const response = await fetch(`/fringe/ticket-availability/refresh/${path}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(body),
        })

        const data = await response.json().catch(() => null)

        if (data?.blocked) throw { blocked: true }
        if (data?.vanished) throw { vanished: true }
        if (!response.ok || !data) throw new Error(`HTTP ${response.status}`)

        return data
    },

    async refresh() {
        if (this.loading) return

        this.loading = true
        this.error = ''
        this.progress = 'checking showtimes…'
        let scraped = 0
        let total = 0

        try {
            const { pending } = await this.post('start', { event: eventId })
            total = pending.length

            for (const id of pending) {
                this.progress = `refreshing ${scraped + 1} of ${total}`
                await this.post('performance', { event: eventId, performance: id })
                scraped++
            }

            this.progress = 'updating…'
            const data = await this.post('finish', { event: eventId })

            if (this.$refs.showtimes) this.$refs.showtimes.innerHTML = data.showtimes_html
            if (this.$refs.tags) this.$refs.tags.innerHTML = data.tags_html
            if (this.$refs.checked) this.$refs.checked.textContent = data.checked
        } catch (e) {
            this.error = e?.blocked
                ? (scraped ? `ticket site blocked us after ${scraped} of ${total} — try later` : 'ticket site blocked us — try later')
                : e?.vanished
                    ? 'ticket site returned no showtimes — kept the last data, try later'
                    : 'refresh failed'
        } finally {
            this.loading = false
            this.progress = ''
        }
    },
}))

// Sales leaderboard (admin only): re-sort the table rows in place when the selector changes.
// Rows carry their numbers in data-tickets / data-pct; ranks are renumbered from the new order.
Alpine.data('leaderboard', () => ({
    sortBy: 'tickets',

    init() {
        this.$watch('sortBy', () => this.apply())
        this.apply()
    },

    apply() {
        const body = this.$refs.body
        const rows = [...body.querySelectorAll('tr')]
        const key = this.sortBy === 'percent' ? 'pct' : 'tickets'

        rows.sort((a, b) =>
            (Number(b.dataset[key]) - Number(a.dataset[key])) ||
            (Number(b.dataset.tickets) - Number(a.dataset.tickets))
        )

        rows.forEach((row, i) => {
            row.querySelector('.js-rank').textContent = i + 1
            body.appendChild(row)
        })
    },
}))

// Reviews index: hide rows that don't match the category dropdown / name search. Rows carry
// data-title and data-categories (space-separated raw slugs); matching is done here so the
// template never resolves a taxonomy term per row. Curly apostrophes in titles are folded to
// straight ones so typing "rodgers and hammerstein's" finds "Hammerstein’s".
Alpine.data('reviewFilter', () => ({
    query: '',
    category: '',
    hideSoldOut: false,
    shown: 0,
    vibesShown: 0,

    get filtering() {
        return this.query.trim() !== '' || this.category !== '' || this.hideSoldOut
    },

    init() {
        this.$watch('query', () => this.apply())
        this.$watch('category', () => this.apply())
        this.$watch('hideSoldOut', () => this.apply())
    },

    normalize(text) {
        return text.toLowerCase().replace(/[’‘]/g, "'")
    },

    apply() {
        const needle = this.normalize(this.query.trim())
        let shown = 0
        let vibesShown = 0

        for (const row of this.$root.querySelectorAll('li[data-title]')) {
            const matches =
                (!needle || this.normalize(row.dataset.title).includes(needle)) &&
                (!this.category || row.dataset.categories.split(' ').includes(this.category)) &&
                (!this.hideSoldOut || row.dataset.soldOut !== '1')

            // Not a `hidden` class, and not a plain inline style either: the rows carry
            // `sm:grid`, and the Tailwind config's `important: true` makes that
            // `display: grid !important`, which beats both a utility class and a normal
            // inline style. Only an inline declaration marked important outranks it.
            if (matches) {
                row.style.removeProperty('display')
            } else {
                row.style.setProperty('display', 'none', 'important')
            }
            // Vibes rows (the good-vibes list under the main table) filter with the same
            // rules but count separately: the "N of M shows" counter describes the review
            // table, and vibesShown only decides whether the vibes section shows at all.
            if (matches) row.dataset.vibes === '1' ? vibesShown++ : shown++
        }

        this.shown = shown
        this.vibesShown = vibesShown
    },
}))

// The content hub (/posts, and /videos opening on Watch). Items carry data-kind (read,
// both, watch), data-video and data-topics (space-separated term slugs). Read hides
// anything that's only a video; Watch shows anything with a video. A month with nothing
// left shows nothing, not an orphaned heading. `?topic=` and `?show=` set the filters on
// arrival, so the old site's redirects (`/posts?topic=dessert-people`) still land filtered,
// and the URL follows the filters so a filtered view can be shared.
Alpine.data('contentHub', (initialShow = 'all') => ({
    show: initialShow,
    topic: '',
    shown: 0,

    init() {
        const params = new URLSearchParams(window.location.search)
        if (params.get('topic')) this.topic = params.get('topic')
        if (['all', 'read', 'watch'].includes(params.get('show'))) this.show = params.get('show')

        this.$watch('show', () => this.apply(true))
        this.$watch('topic', () => this.apply(true))
        this.apply(false)
    },

    apply(updateUrl) {
        // Inline important, for the same reason as reviewFilter above: the Tailwind
        // config's `important: true` means only an inline declaration marked important
        // reliably beats utility classes.
        const toggle = (el, visible) => visible
            ? el.style.removeProperty('display')
            : el.style.setProperty('display', 'none', 'important')

        let shown = 0

        for (const item of this.$root.querySelectorAll('[data-hub-item]')) {
            const kindOk = this.show === 'all'
                || (this.show === 'read' ? item.dataset.kind !== 'watch' : item.dataset.video === '1')
            const topicOk = !this.topic || (item.dataset.topics || '').split(/\s+/).includes(this.topic)
            const visible = kindOk && topicOk

            toggle(item, visible)
            if (visible) shown++
        }

        for (const group of this.$root.querySelectorAll('[data-hub-shorts], [data-hub-month]')) {
            toggle(group, [...group.querySelectorAll('[data-hub-item]')].some(i => i.style.display !== 'none'))
        }

        for (const hubs of this.$root.querySelectorAll('[data-hub-hubs]')) toggle(hubs, !this.topic)

        this.shown = shown

        if (updateUrl) {
            const url = new URL(window.location)
            this.topic ? url.searchParams.set('topic', this.topic) : url.searchParams.delete('topic')
            this.show !== initialShow ? url.searchParams.set('show', this.show) : url.searchParams.delete('show')
            history.replaceState(null, '', url)
        }
    },
}))


// /bylaw-exemption. Draws the card on a canvas so the preview is the download. Text sits
// where the card's blank sits: measured on the 1000×750 template, rotated with it.
Alpine.data('exemptionCard', (templateUrl) => ({
    header: '',
    caption: '',
    image: null,
    presets: [
        ['I cannot properly navigate roundabouts', 'To me, they are the traffic equivalent of a sudoku puzzle'],
        ['I cannot use my signal lights', 'Prepare yourself for some unpredictable sh*t'],
        ['I cannot wear a mask or face covering', 'Please give me 2 metres of space.'],
        ['I cannot pay taxes or parking tickets', "Please don't bother trying to collect payment."],
        ['I am going to sneeze in your face', 'Here comes the mucus!!!'],
        ['I cannot chew quietly', 'You better hope those Beats By Dre headphones go to 11.'],
        ['I can have more than 6 cats and 3 dogs in my household', "It won't smell too bad."],
        ['Hello.', 'My name is Inigo Montoya. You killed my father. Prepare to die.'],
        ['I cannot make the playoffs', 'I think high draft picks are worth more than cups.'],
        ['Get out of jail free card', 'This card may be kept until needed or sold.'],
        ['My blue lamborghini is not too loud', "It's accessible so even the hard of hearing can enjoy it."],
        ['I only vote no', 'Vote yes to me being mayor.'],
        ['I cannot pay for my own MBA', 'Please give me $44,000.'],
        ['I am legally entitled to park in a bike lane', 'I pay road tax.'],
        ["Can't pay $3.50 to use transit?", 'What if we enforce a $250 fine?'],
    ],

    init() {
        this.shuffle()
        this.image = new Image()
        this.image.onload = () => this.draw()
        this.image.src = templateUrl
        // Redraw once the webfont lands; the first paint may have used the fallback.
        if (document.fonts) document.fonts.ready.then(() => this.draw())
    },

    shuffle() {
        const [header, caption] = this.presets[Math.floor(Math.random() * this.presets.length)]
        this.header = header
        this.caption = caption
    },

    wrap(ctx, text, maxWidth) {
        const lines = []
        let line = ''
        for (const word of text.split(/\s+/)) {
            const trial = line ? `${line} ${word}` : word
            if (ctx.measureText(trial).width > maxWidth && line) { lines.push(line); line = word } else line = trial
        }
        if (line) lines.push(line)
        return lines
    },

    draw() {
        const canvas = this.$refs.canvas
        if (!canvas || !this.image || !this.image.complete) return
        const ctx = canvas.getContext('2d')
        ctx.clearRect(0, 0, canvas.width, canvas.height)
        ctx.drawImage(this.image, 0, 0, canvas.width, canvas.height)

        ctx.save()
        ctx.translate(112, 246)
        ctx.rotate(10.6 * Math.PI / 180)
        ctx.fillStyle = 'rgb(240, 213, 192)'
        ctx.shadowColor = 'rgb(156, 54, 10)'
        ctx.shadowOffsetX = 8
        ctx.shadowOffsetY = 11
        ctx.shadowBlur = 9
        ctx.textBaseline = 'top'

        ctx.font = '900 54px "Roboto Slab", Georgia, serif'
        let y = 0
        for (const line of this.wrap(ctx, this.header, 560)) { ctx.fillText(line, 0, y); y += 62 }
        y += 18
        ctx.font = 'italic 400 25px "Roboto Slab", Georgia, serif'
        for (const line of this.wrap(ctx, this.caption, 390)) { ctx.fillText(line, 0, y); y += 37 }
        ctx.restore()
    },

    download() {
        this.draw()
        this.$refs.canvas.toBlob((blob) => {
            const link = document.createElement('a')
            link.download = 'exemption-card.png'
            link.href = URL.createObjectURL(blob)
            link.click()
            setTimeout(() => URL.revokeObjectURL(link.href), 1000)
        }, 'image/png')
    },
}))

// The newsletter signup box, _email-subscribe. Posts in the background and swaps the form
// for a thank-you; the form still posts normally if this never runs. `initial` is the
// server's flash ('done' or 'error') after a no-JavaScript post, so a reload lands in the
// same state.
Alpine.data('subscribeForm', (initial = 'idle') => ({
    status: initial,

    async submit(form) {
        if (this.status === 'sending') return
        this.status = 'sending'

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new FormData(form),
            })

            this.status = response.ok ? 'done' : 'error'
        } catch {
            this.status = 'error'
        }
    },
}))

Alpine.start()
