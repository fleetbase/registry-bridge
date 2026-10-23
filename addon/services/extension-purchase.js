import Service, { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import isModel from '@fleetbase/ember-core/utils/is-model';
import { isServiceRecord, priceOf } from '../utils/extension-state';

export const PURCHASE_NAMESPACE = '~registry/v1';
export const HANDOFF_PARAM = 'purchase_handoff';
export const RETURN_PARAM = 'purchase_return';
export const LEGACY_SESSION_PARAM = 'checkout_session_id';
export const HANDOFF_STORAGE_KEY = 'registry-bridge:purchase-handoff';

/** Hand-off polling: every 2 s for up to 90 s, then the purchase is left to the webhook. */
export const POLL_INTERVAL = 2000;
export const POLL_TIMEOUT = 90000;

function readStorage(key) {
    try {
        return JSON.parse(window.sessionStorage.getItem(key) ?? 'null');
    } catch {
        return null;
    }
}

function writeStorage(key, value) {
    try {
        if (value === null) {
            window.sessionStorage.removeItem(key);
        } else {
            window.sessionStorage.setItem(key, JSON.stringify(value));
        }
    } catch {
        // Private windows can refuse storage; the hand-off then has to come back in the URL
    }
}

function removeUrlParams(...names) {
    const url = new URL(window.location.href);
    names.forEach((name) => url.searchParams.delete(name));
    if (url.href !== window.location.href) {
        window.history.replaceState({ path: url.href }, '', url.href);
    }
}

/**
 * The URL Stripe sends the buyer back to: the current page with the panel's
 * extension and a return marker. The service may add `purchase_handoff`; the
 * id is also kept in session storage in case it does not.
 */
export function checkoutReturnUrl(href, extensionId) {
    const url = new URL(href);
    url.searchParams.set('extension_id', extensionId);
    url.searchParams.set(RETURN_PARAM, '1');
    url.searchParams.delete(HANDOFF_PARAM);
    return url.href;
}

/**
 * Buying a paid extension (canvas C6).
 *
 * Registry Service listings go to the service's hosted Stripe Checkout
 * (`POST ~registry/v1/purchases/checkout`); on return the Finalizing state
 * polls the hand-off until the service's webhook has recorded the licence.
 * Cloud-catalog (v1) records keep the embedded Stripe checkout until that path
 * is retired.
 */
export default class ExtensionPurchaseService extends Service {
    @service modalsManager;
    @service notifications;
    @service fetch;
    @service stripe;
    @service intl;
    @service urlSearchParams;
    @service extensionPanel;
    @service extensionInstaller;
    @service currentUser;

    pollInterval = POLL_INTERVAL;
    pollTimeout = POLL_TIMEOUT;

    /** Replaced in tests. */
    redirect = (url) => window.location.assign(url);
    schedule = (fn, ms) => later(fn, ms);

    @action purchase(extension) {
        if (isServiceRecord(extension)) {
            return this.startServiceCheckout(extension);
        }

        return this.startLegacyCheckout(extension);
    }

    /**
     * Ask the service for a hosted checkout and send the buyer there.
     */
    async startServiceCheckout(extension) {
        const extensionId = extension.public_id ?? extension.id;
        try {
            const { url, handoff } = await this.fetch.post(
                'purchases/checkout',
                { extension: extensionId, return_url: checkoutReturnUrl(window.location.href, extensionId) },
                { namespace: PURCHASE_NAMESPACE }
            );
            writeStorage(HANDOFF_STORAGE_KEY, { handoff, extension: extensionId, startedAt: Date.now() });
            this.redirect(url);
            return { url, handoff };
        } catch (error) {
            this.notifications.serverError(error);
            return null;
        }
    }

    /**
     * Resume after a checkout redirect: the service hand-off, or a legacy
     * Stripe session. Called once the panel's extension is known.
     *
     * @return {Promise<String|null>} the final status, or null when there was nothing to resume
     */
    @action async resume() {
        const extensionId = this.urlSearchParams.get('extension_id');
        const legacySession = this.urlSearchParams.get(LEGACY_SESSION_PARAM);
        if (legacySession && extensionId) {
            return this.finalizeLegacy(legacySession, extensionId);
        }

        const returned = this.urlSearchParams.get(RETURN_PARAM) || this.urlSearchParams.get(HANDOFF_PARAM);
        if (!returned) {
            return null;
        }

        const stored = readStorage(HANDOFF_STORAGE_KEY);
        const handoff = this.urlSearchParams.get(HANDOFF_PARAM) || (stored && (!extensionId || stored.extension === extensionId) ? stored.handoff : null);
        removeUrlParams(HANDOFF_PARAM, RETURN_PARAM);
        writeStorage(HANDOFF_STORAGE_KEY, null);
        if (!handoff) {
            return null;
        }

        return this.finalize(handoff, extensionId);
    }

    /**
     * Show Finalizing and poll the hand-off until it completes, fails, or the
     * timeout leaves it to the webhook.
     */
    async finalize(handoff, extensionId) {
        const extension = extensionId ? await this.extensionPanel.openById(extensionId) : null;
        this.showStatus({ phase: 'finalizing', extension, handoff });

        const started = Date.now();
        let result = { status: 'pending' };
        while (result.status === 'pending' && Date.now() - started < this.pollTimeout) {
            try {
                result = await this.fetch.get(`purchases/${encodeURIComponent(handoff)}`, {}, { namespace: PURCHASE_NAMESPACE });
            } catch (error) {
                result = { status: 'pending', message: error?.message };
            }
            if (result?.status === 'pending') {
                await new Promise((resolve) => this.schedule(resolve, this.pollInterval));
            }
        }

        const status = result?.status ?? 'pending';
        const refreshed = status === 'completed' ? await this.extensionPanel.reload() : extension;
        this.modalsManager.setOptions({
            phase: status === 'completed' ? 'success' : status === 'failed' ? 'failed' : 'processing',
            extension: refreshed ?? extension,
            receiptUrl: result?.receipt_url ?? null,
            serviceStatus: result?.service_status ?? null,
            message: result?.message ?? null,
            ...this.statusButtons(status === 'completed' ? 'success' : status === 'failed' ? 'failed' : 'processing'),
        });

        return status;
    }

    statusButtons(phase) {
        const t = (key) => this.intl.t(`registry-bridge.${key}`);
        switch (phase) {
            case 'success':
                return {
                    hideFooterActions: false,
                    acceptButtonText: t('purchase.success.install-now'),
                    acceptButtonIcon: 'download',
                    declineButtonText: t('purchase.success.later'),
                    hideDeclineButton: false,
                };
            case 'failed':
                return {
                    hideFooterActions: false,
                    acceptButtonText: t('purchase.failed.try-again'),
                    acceptButtonIcon: 'rotate-right',
                    declineButtonText: t('common.close'),
                    hideDeclineButton: false,
                };
            case 'processing':
                return { hideFooterActions: false, acceptButtonText: t('common.close'), acceptButtonIcon: 'check', hideDeclineButton: true };
            default:
                return { hideFooterActions: true };
        }
    }

    showStatus({ phase, extension, handoff = null }) {
        return this.modalsManager.show('modals/extension-purchase-status', {
            title: extension ? this.intl.t('registry-bridge.purchase.title', { name: extension.name }) : this.intl.t('registry-bridge.purchase.finalizing.title'),
            modalClass: 'flb--extension-modal modal-md',
            modalHeaderClass: 'flb--extension-modal-header',
            phase,
            extension,
            handoff,
            companyName: this.currentUser.company?.name ?? null,
            backdropClose: false,
            closeButton: false,
            keepOpen: true,
            ...this.statusButtons(phase),
            confirm: (modal) => this.onStatusConfirm(modal),
            decline: (modal) => modal.done(),
        });
    }

    async onStatusConfirm(modal) {
        const phase = modal.getOption('phase');
        const extension = modal.getOption('extension');
        await modal.done();

        if (phase === 'success' && extension) {
            return this.extensionInstaller.install(extension, { onCompleted: () => this.extensionPanel.reload() });
        }
        if (phase === 'failed' && extension) {
            return this.purchase(extension);
        }
    }

    /**
     * v1 cloud catalog: Stripe embedded checkout inside a modal (unchanged flow).
     */
    async startLegacyCheckout(extension) {
        const checkout = await this.stripe.initEmbeddedCheckout({
            fetchClientSecret: () => this.fetchLegacyClientSecret(extension),
        });

        return this.modalsManager.show('modals/extension-purchase-form', {
            title: this.intl.t('registry-bridge.purchase.title', { name: extension.name }),
            modalClass: 'stripe-extension-purchase',
            modalFooterClass: 'hidden-i',
            extension,
            price: formatCurrency(priceOf(extension).amount, extension.currency),
            facilitatorFee: null,
            checkoutElementInserted: (el) => checkout.mount(el),
            decline: async (modal) => {
                checkout.destroy();
                await modal.done();
            },
        });
    }

    async fetchLegacyClientSecret(extension) {
        try {
            const { clientSecret } = await this.fetch.post('payments/create-checkout-session', { extension: extension.id, uri: window.location.pathname }, { namespace: PURCHASE_NAMESPACE });
            return clientSecret;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    async finalizeLegacy(checkoutSessionId, extensionId) {
        const extension = await this.extensionPanel.openById(extensionId);
        this.showStatus({ phase: 'finalizing', extension });

        let status = 'failed';
        let refreshed = extension;
        try {
            const response = await this.fetch.post('payments/get-checkout-session', { checkout_session_id: checkoutSessionId, extension: extensionId }, { namespace: PURCHASE_NAMESPACE });
            status = response?.status === 'complete' || response?.status === 'purchase_complete' ? 'completed' : 'failed';
            const model = response?.extension ? this.fetch.jsonToModel(response.extension, 'registry-extension') : null;
            if (isModel(model)) {
                refreshed = model;
                this.extensionPanel.open(model);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }

        removeUrlParams(LEGACY_SESSION_PARAM);
        const phase = status === 'completed' ? 'success' : 'failed';
        this.modalsManager.setOptions({ phase, extension: refreshed, ...this.statusButtons(phase) });

        return status;
    }
}
