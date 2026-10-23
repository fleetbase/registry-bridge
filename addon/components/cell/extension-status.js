import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import { cardModel } from '../../utils/extension-state';

/**
 * Status badges for an extension row. `@column.variant`:
 *
 * - `catalog` (default): price and the card's state badges, as on Explore
 * - `installed`: Up to date, Update available or Core service
 * - `purchased`: Installed vX, Ready to install or Self-hosted install
 */
export default class CellExtensionStatusComponent extends Component {
    @service intl;

    get extension() {
        return this.args.row ?? {};
    }

    get badges() {
        const extension = this.extension;
        const t = (key, params) => this.intl.t(`registry-bridge.${key}`, params);

        switch (this.args.column?.variant) {
            case 'installed':
                if (extension.core_service === true || extension.core_extension === true) {
                    return [{ text: t('installed.status.core'), status: 'default' }];
                }
                return extension.update_available === true
                    ? [{ text: t('installed.status.update-available'), status: 'info' }]
                    : [{ text: t('installed.status.up-to-date'), status: 'success' }];
            case 'purchased':
                if (extension.is_installed === true) {
                    return [{ text: t('purchased.status.installed', { version: extension.installed_version || extension.current_bundle_version || '' }).trim(), status: 'success' }];
                }
                if (extension.self_managed === true) {
                    return [{ text: t('purchased.status.self-hosted'), status: 'default' }];
                }
                return [{ text: t('card.ready-to-install'), status: 'info' }];
            default: {
                const model = cardModel(extension);
                const price = model.priceBadge.label
                    ? { text: this.intl.t(model.priceBadge.label.key, model.priceBadge.label.params), status: model.priceBadge.status }
                    : { text: formatCurrency(model.priceBadge.amount, model.priceBadge.currency), status: model.priceBadge.status };
                return [price, ...model.badges.map((badge) => ({ text: this.intl.t(badge.label.key, badge.label.params), status: badge.status }))];
            }
        }
    }
}
