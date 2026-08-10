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

Alpine.start()
