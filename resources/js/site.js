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

// Posts hub: show only the rows tagged with the selected topic pill. Rows carry
// data-topics (space-separated term slugs); one topic at a time, empty string means all.
Alpine.data('postFilter', () => ({
    topic: '',
    shown: 0,

    init() {
        this.$watch('topic', () => this.apply())
    },

    apply() {
        let shown = 0

        for (const row of this.$root.querySelectorAll('li[data-topics]')) {
            const matches = !this.topic ||
                row.dataset.topics.split(/\s+/).includes(this.topic)

            // Inline important, for the same reason as reviewFilter above: the Tailwind
            // config's `important: true` means only an inline declaration marked
            // important reliably wins over utility classes.
            if (matches) {
                row.style.removeProperty('display')
                shown++
            } else {
                row.style.setProperty('display', 'none', 'important')
            }
        }

        this.shown = shown
    },
}))

Alpine.start()
