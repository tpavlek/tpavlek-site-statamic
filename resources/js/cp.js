import VideoDistributionFieldtype from './components/fieldtypes/VideoDistributionFieldtype.vue';

Statamic.booting(() => {
    VideoDistributionFieldtype.mixins = [window.__STATAMIC__.core.FieldtypeMixin];
    Statamic.$components.register('video_distribution-fieldtype', VideoDistributionFieldtype);
});
