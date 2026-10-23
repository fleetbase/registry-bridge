import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import { CTA, PERMISSIONS, detailCta, priceOf } from '../../utils/extension-state';

/**
 * Header of the extension detail panel: identity, publisher and category
 * pills, trust badges and the call to action for this extension on this
 * instance (canvas C4 CTA matrix).
 */
export default class ExtensionPanelHeaderComponent extends Component {
    @service extensionPanel;
    @service extensionInstaller;
    @service extensionPurchase;
    @service registryContext;
    @service abilities;
    @service currentUser;
    @service hostRouter;
    @service intl;

    get extension() {
        return this.args.resource ?? this.extensionPanel.extension ?? {};
    }

    get cta() {
        return detailCta(this.extension, {
            canInstall: this.abilities.can(PERMISSIONS.install),
            canPurchase: this.abilities.can(PERMISSIONS.purchase),
        });
    }

    /** `is.install`, `is.registration`, … for the template's branches. */
    get is() {
        const kind = this.cta.kind;
        return Object.fromEntries(Object.entries(CTA).map(([name, value]) => [name.toLowerCase().replace(/_(\w)/g, (_, c) => c.toUpperCase()), value === kind]));
    }

    get price() {
        const price = priceOf(this.extension);
        if (price.free) {
            return { text: this.intl.t('registry-bridge.card.free'), status: 'success', original: null };
        }
        if (this.extension.is_purchased === true) {
            return { text: this.intl.t('registry-bridge.card.purchased'), status: 'success', original: null };
        }

        return { text: formatCurrency(price.amount, price.currency), status: 'info', original: price.original ? formatCurrency(price.original, price.currency) : null };
    }

    get purchaseText() {
        return this.intl.t('registry-bridge.detail.cta.purchase', { price: formatCurrency(priceOf(this.extension).amount, this.extension.currency) });
    }

    get publisher() {
        const publisher = this.extension.meta?.publisher ?? {};
        return { name: publisher.name ?? this.extension.publisher_name, verified: publisher.is_verified === true, avatar_url: publisher.avatar_url };
    }

    get versionLine() {
        const version = this.extension.latest_version || this.extension.current_bundle_version || this.extension.version;
        return [version ? `v${version}` : null, this.extension.updatedAt ? this.intl.t('registry-bridge.detail.updated', { date: this.extension.updatedAt }) : null]
            .filter(Boolean)
            .join(' · ');
    }

    get canUninstall() {
        return this.extension.is_installed === true && this.abilities.can(PERMISSIONS.uninstall) && this.extension.core_extension !== true;
    }

    get canRegister() {
        return this.currentUser.isAdmin === true;
    }

    @action close() {
        if (typeof this.args.onClose === 'function') {
            return this.args.onClose();
        }
        return this.extensionPanel.close();
    }

    @action install() {
        return this.extensionInstaller.install(this.extension, { onCompleted: () => this.extensionPanel.reload() });
    }

    @action update() {
        return this.extensionInstaller.update(this.extension, { onCompleted: () => this.extensionPanel.reload() });
    }

    @action uninstall() {
        return this.extensionInstaller.uninstall(this.extension, { onCompleted: () => this.extensionPanel.reload() });
    }

    @action purchase() {
        return this.extensionPurchase.purchase(this.extension);
    }

    /** A refused checkout (already licensed, publisher not set up, …) shown under the call to action. */
    get purchaseError() {
        const error = this.extensionPurchase.lastError;
        return error && error.extension === (this.extension.public_id ?? this.extension.id) ? error.message : null;
    }

    @action openPortal() {
        if (this.registryContext.publisherUrl) {
            window.open(this.registryContext.publisherUrl, '_blank', 'noopener');
        }
    }

    @action openRegistration() {
        this.extensionPanel.close();
        return this.hostRouter.transitionTo('console.admin.virtual', 'extension-registry', { queryParams: { view: 'registry-config' } });
    }
}
