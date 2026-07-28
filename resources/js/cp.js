import VideoDistributionFieldtype from './components/fieldtypes/VideoDistributionFieldtype.vue';
import TicketImportFieldtype from './components/fieldtypes/TicketImportFieldtype.vue';

Statamic.booting(() => {
    VideoDistributionFieldtype.mixins = [window.__STATAMIC__.core.FieldtypeMixin];
    Statamic.$components.register('video_distribution-fieldtype', VideoDistributionFieldtype);

    TicketImportFieldtype.mixins = [window.__STATAMIC__.core.FieldtypeMixin];
    Statamic.$components.register('ticket_import-fieldtype', TicketImportFieldtype);
});
