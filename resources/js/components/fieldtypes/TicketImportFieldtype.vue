<template>
    <div class="ticket-import-fieldtype">
        <div class="flex items-center gap-2">
            <input
                type="url"
                v-model="url"
                placeholder="https://tickets.fringetheatre.ca/event/…"
                class="input-text flex-1"
                :disabled="loading"
                @keydown.enter.prevent="populate"
            />
            <ui-button
                @click="populate"
                :disabled="loading || !url"
                :text="loading ? 'Fetching…' : 'Populate'"
            />
        </div>
    </div>
</template>

<script>
export default {
    data() {
        return {
            url: '',
            loading: false,
        };
    },

    methods: {
        populate() {
            if (!this.url || this.loading) return;

            this.loading = true;

            this.$axios.post(cp_url('fringe-ticket-import'), { url: this.url })
                .then((response) => {
                    Object.entries(response.data.fields).forEach(([handle, field]) => {
                        if (field.meta !== null && field.meta !== undefined) {
                            this.publishContainer.setFieldMeta(handle, field.meta);
                        }
                        this.publishContainer.setFieldValue(handle, field.value);
                    });
                    this.$toast.success('Show details imported');
                })
                .catch((error) => {
                    this.$toast.error(error.response?.data?.message || 'Import failed');
                })
                .finally(() => {
                    this.loading = false;
                });
        },
    },
};
</script>
