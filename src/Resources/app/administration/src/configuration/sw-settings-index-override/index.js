import pluginIcon from '../../../../../config/plugin.png';
import template from './sw-settings-index.html.twig';

Shopware.Component.override('sw-settings-index', {
    template,

    data() {
        return {
            pluginIcon,
        };
    },
});

