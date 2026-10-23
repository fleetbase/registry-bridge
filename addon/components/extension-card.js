import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import { cardModel } from '../utils/extension-state';

/**
 * The one extension card (canvas C2): Explore, categories and the grid views
 * of Installed and Purchased. The name opens the detail panel; the footer
 * holds a real button whose action follows the card's state
 * (`ExtensionCard::Action`).
 *
 * @argument {RegistryExtensionModel} resource
 * @argument {Function} [onOpen] replaces opening the detail panel
 * @argument {Function} [onAction] called with `(action, extension)` instead of the default action
 */
export default class ExtensionCardComponent extends Component {
    @service extensionPanel;
    @service intl;

    get extension() {
        return this.args.resource ?? {};
    }

    get model() {
        return cardModel(this.extension);
    }

    get price() {
        const badge = this.model.priceBadge;
        if (badge.label) {
            return { text: this.intl.t(badge.label.key, badge.label.params), status: badge.status, original: null };
        }

        return {
            text: formatCurrency(badge.amount, badge.currency),
            original: badge.original ? formatCurrency(badge.original, badge.currency) : null,
            status: badge.status,
        };
    }

    get badges() {
        return this.model.badges.map((badge) => ({ text: this.intl.t(badge.label.key, badge.label.params), status: badge.status }));
    }

    get subtitle() {
        return [this.extension.publisher_name, this.extension.category_name].filter(Boolean).join(' · ');
    }

    get description() {
        return this.extension.description || this.extension.subtitle || null;
    }

    get meta() {
        return [this.model.version ? `v${this.model.version}` : null, this.extension.updatedAgo].filter(Boolean).join(' · ');
    }

    @action openDetails(options = {}) {
        if (typeof this.args.onOpen === 'function') {
            return this.args.onOpen(this.extension, options);
        }

        return this.extensionPanel.open(this.extension, options);
    }
}
