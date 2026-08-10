// Alpine.js handles the show/hide of the mobile nav
import Alpine from 'alpinejs'

// Per-show refresh on the sold-out report (admin only). Posts to the refresh endpoint, which
// scrapes that one show now and returns freshly rendered showtimes + header tags; while it
// runs the row shows skeleton loaders, then swaps the new markup in.
Alpine.data('showRefresh', (eventId) => ({
    loading: false,
    error: false,

    async refresh() {
        if (this.loading) return

        this.loading = true
        this.error = false

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content

            const response = await fetch('/fringe/ticket-availability/refresh', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({ event: eventId }),
            })

            if (!response.ok) throw new Error(`HTTP ${response.status}`)

            const data = await response.json()

            if (this.$refs.showtimes) this.$refs.showtimes.innerHTML = data.showtimes_html
            if (this.$refs.tags) this.$refs.tags.innerHTML = data.tags_html
            if (this.$refs.checked) this.$refs.checked.textContent = data.checked
        } catch (e) {
            this.error = true
        } finally {
            this.loading = false
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
