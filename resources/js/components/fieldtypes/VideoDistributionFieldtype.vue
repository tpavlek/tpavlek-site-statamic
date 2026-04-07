<template>
    <div class="video-distribution-fieldtype">
        <div v-if="!entryId" class="text-sm text-gray-600">
            Save the entry first to enable distribution.
        </div>

        <div class="bg-white dark:bg-gray-850 rounded-xl ring ring-gray-200 dark:ring-gray-700/80 shadow-none">
            <div v-for="(platform, index) in platforms" :key="platform.name"
                 class="flex items-center justify-between px-4 py-3"
                 :class="{ 'border-t border-gray-200 dark:border-white/10': index > 0 }">
                <div class="flex items-center gap-3">
                    <span class="font-medium text-sm">{{ platform.label }}</span>
                    <span v-if="platform.status" class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-full" :class="statusClasses(platform.status)">
                        <span class="w-1.5 h-1.5 rounded-full" :class="statusDotClass(platform.status)"></span>
                        {{ statusLabel(platform.status) }}
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <a v-if="platform.status === 'success' && platform.platform_id && platform.name === 'youtube'"
                       :href="'https://youtube.com/shorts/' + platform.platform_id"
                       target="_blank"
                       class="text-xs text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100 underline">
                        View on YouTube
                    </a>

                    <a v-if="platform.status === 'success' && platform.platform_id && platform.name === 'twitter'"
                       :href="'https://x.com/i/status/' + platform.platform_id"
                       target="_blank"
                       class="text-xs text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100 underline">
                        View on Twitter
                    </a>

                    <a v-if="platform.status === 'success' && platform.platform_id && platform.name === 'bluesky'"
                       :href="blueskyWebUrl(platform.platform_id)"
                       target="_blank"
                       class="text-xs text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100 underline">
                        View on Bluesky
                    </a>

                    <div v-if="platform.status === 'failed' && platform.error_message"
                         class="text-xs text-red-800 dark:text-red-300 max-w-xs truncate" :title="platform.error_message">
                        {{ platform.error_message }}
                    </div>

                    <div v-if="showDistributeButton(platform)" class="relative group">
                        <ui-button
                            @click="distribute(platform.name)"
                            :disabled="distributing || !platform.can_distribute"
                            :text="platform.status === 'failed' ? 'Retry' : 'Distribute'"
                            size="sm"
                        />
                        <div v-if="!platform.can_distribute && platform.blockers && platform.blockers.length"
                             class="absolute bottom-full right-0 mb-1.5 hidden group-hover:block w-56 px-3 py-2 text-xs text-white bg-gray-900 dark:bg-gray-600 rounded shadow-lg z-20">
                            <div v-for="blocker in platform.blockers" :key="blocker">{{ blocker }}</div>
                        </div>
                    </div>

                    <div v-if="platform.status" class="relative">
                        <button @click="toggleMenu(platform.name)"
                                class="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-gray-600 dark:text-gray-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                <circle cx="10" cy="4" r="1.5" />
                                <circle cx="10" cy="10" r="1.5" />
                                <circle cx="10" cy="16" r="1.5" />
                            </svg>
                        </button>
                        <div v-if="openMenu === platform.name"
                             class="absolute right-0 mt-1 w-44 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded shadow-lg z-10">
                            <button @click="clearDistribution(platform.name)"
                                    class="w-full text-left text-sm px-3 py-2 text-red-700 dark:text-red-300 hover:bg-gray-100 dark:hover:bg-gray-600 rounded transition-colors">
                                Clear Distribution
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    data() {
        return {
            entryId: this.meta.entryId,
            platforms: this.meta.platforms || [],
            distributing: false,
            pollInterval: null,
            openMenu: null,
        };
    },

    mounted() {
        this.startPollingIfNeeded();
    },

    beforeUnmount() {
        this.stopPolling();
    },

    methods: {
        distribute(platformName) {
            this.distributing = true;

            this.$axios.post(cp_url('video-distribution/' + this.entryId + '/distribute'), {
                platform: platformName,
            }).then(() => {
                this.refreshStatus();
                this.startPolling();
            }).catch((error) => {
                this.$toast.error(error.response?.data?.error || 'Distribution failed');
            }).finally(() => {
                this.distributing = false;
            });
        },

        refreshStatus() {
            this.$axios.get(cp_url('video-distribution/' + this.entryId + '/status'))
                .then((response) => {
                    this.platforms = response.data.platforms;
                    this.syncFieldValues();
                    this.startPollingIfNeeded();
                });
        },

        syncFieldValues() {
            this.platforms.forEach(platform => {
                if (platform.status === 'success' && platform.platform_id && platform.field_handle) {
                    this.publishContainer.setFieldValue(platform.field_handle, platform.platform_id);
                }
            });
        },

        startPollingIfNeeded() {
            const hasActive = this.platforms.some(p =>
                ['pending', 'transcoding', 'uploading'].includes(p.status)
            );
            if (hasActive) {
                this.startPolling();
            } else {
                this.stopPolling();
            }
        },

        startPolling() {
            this.stopPolling();
            this.pollInterval = setInterval(() => this.refreshStatus(), 5000);
        },

        stopPolling() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        toggleMenu(platformName) {
            this.openMenu = this.openMenu === platformName ? null : platformName;
        },

        clearDistribution(platformName) {
            this.openMenu = null;

            this.$axios.delete(cp_url('video-distribution/' + this.entryId + '/' + platformName + '/clear'))
                .then((response) => {
                    if (response.data.cleared_field) {
                        this.publishContainer.setFieldValue(response.data.cleared_field, null);
                    }
                    this.refreshStatus();
                }).catch((error) => {
                    this.$toast.error(error.response?.data?.error || 'Failed to clear distribution');
                });
        },

        blueskyWebUrl(atUri) {
            // at://did:plc:xxx/app.bsky.feed.post/yyy -> https://bsky.app/profile/did:plc:xxx/post/yyy
            const match = atUri.match(/^at:\/\/(.*?)\/app\.bsky\.feed\.post\/(.*)$/);
            if (match) {
                return 'https://bsky.app/profile/' + match[1] + '/post/' + match[2];
            }
            return atUri;
        },

        showDistributeButton(platform) {
            if (['pending', 'transcoding', 'uploading', 'success'].includes(platform.status)) {
                return false;
            }
            return true;
        },

        statusLabel(status) {
            return {
                pending: 'Pending',
                transcoding: 'Transcoding',
                uploading: 'Uploading',
                success: 'Distributed',
                failed: 'Failed',
            }[status] || status;
        },

        statusClasses(status) {
            return {
                pending: 'bg-yellow-200 text-yellow-900 dark:bg-yellow-800 dark:text-yellow-400',
                transcoding: 'bg-blue-200 text-blue-900 dark:bg-blue-800 dark:text-blue-400',
                uploading: 'bg-blue-200 text-blue-900 dark:bg-blue-800 dark:text-blue-400',
                success: 'bg-green-200 text-green-900 dark:bg-green-800 dark:text-green-400',
                failed: 'bg-red-200 text-red-900 dark:bg-red-800 dark:text-red-100',
            }[status] || '';
        },

        statusDotClass(status) {
            return {
                pending: 'bg-yellow-600',
                transcoding: 'bg-blue-600 animate-pulse',
                uploading: 'bg-blue-600 animate-pulse',
                success: 'bg-green-600',
                failed: 'bg-red-600',
            }[status] || 'bg-gray-600';
        },
    },
};
</script>
