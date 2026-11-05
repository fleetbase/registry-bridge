import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { later } from '@ember/runloop';

export const PURCHASE_NAMESPACE = '~registry/v1';
/** Stripe appends these to the return URL (service contract); `purchase_return` is ours. */
export const HANDOFF_PARAM = 'handoff';
export const CANCELLED_PARAM = 'cancelled';
export const RETURN_PARAM = 'purchase_return';
export const HANDOFF_STORAGE_KEY = 'registry-bridge:purchase-handoff';

/** Hand-off polling: every 2 s for about a minute, then the purchase is left to the webhook. */
export const POLL_INTERVAL = 2000;
export const POLL_TIMEOUT = 60000;

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
        // Private windows can refuse storage; the hand-off then comes back in the URL only
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
 * extension and a return marker. Stripe adds `handoff` (and `cancelled=1`
 * when the buyer cancels); a stale one is dropped first.
 */
export function checkoutReturnUrl(href, extensionId) {
    const url = new URL(href);
    url.searchParams.set('extension_id', extensionId);
    url.searchParams.set(RETURN_PARAM, '1');
    url.searchParams.delete(HANDOFF_PARAM);
    url.searchParams.delete(CANCELLED_PARAM);
    return url.href;
}

/**
 * Buying a paid extension (canvas C6) through the Registry Service's hosted
 * Stripe Checkout (`POST ~registry/v1/purchases/checkout`). On return the
 * Finalizing state polls the hand-off until the service's webhook has
 * recorded the licence. A refusal (already licensed, publisher not set up,
 * hosting) is kept in `lastError` for the detail panel to show.
 */
export default class ExtensionPurchaseService extends Service {
    @service modalsManager;
    @service notifications;
    @service fetch;
    @service intl;
    @service urlSearchParams;
    @service extensionPanel;
    @service extensionInstaller;
    @service registryContext;
    @tracked lastError = null;
    @tracked isStarting = false;

    pollInterval = POLL_INTERVAL;
    pollTimeout = POLL_TIMEOUT;

    /** Replaced in tests. */
    redirect = (url) => window.location.assign(url);
    schedule = (fn, ms) => later(fn, ms);

    /**
     * Ask the service for a hosted checkout and send the buyer there.
     */
    @action async purchase(extension) {
        const extensionId = extension.public_id ?? extension.id;
        const returnUrl = checkoutReturnUrl(window.location.href, extensionId);
        this.lastError = null;
        this.isStarting = true;

        try {
            const { url, handoff } = await this.fetch.post('purchases/checkout', { extension: extensionId, return_url: returnUrl, cancel_url: returnUrl }, { namespace: PURCHASE_NAMESPACE });
            writeStorage(HANDOFF_STORAGE_KEY, { handoff, extension: extensionId, startedAt: Date.now() });
            this.redirect(url);
            return { url, handoff };
        } catch (error) {
            this.lastError = { extension: extensionId, message: error?.message ?? this.intl.t('registry-bridge.purchase.failed.title') };
            return null;
        } finally {
            this.isStarting = false;
        }
    }

    /**
     * Resume after a checkout redirect. Called once the console has loaded.
     *
     * @return {Promise<String|null>} the final status, or null when there was nothing to resume
     */
    @action async resume() {
        if (!this.urlSearchParams.get(RETURN_PARAM) && !this.urlSearchParams.get(HANDOFF_PARAM)) {
            return null;
        }

        const extensionId = this.urlSearchParams.get('extension_id');
        const stored = readStorage(HANDOFF_STORAGE_KEY);
        const handoff = this.urlSearchParams.get(HANDOFF_PARAM) || (stored && (!extensionId || stored.extension === extensionId) ? stored.handoff : null);
        const cancelled = this.urlSearchParams.get(CANCELLED_PARAM) === '1';
        removeUrlParams(HANDOFF_PARAM, CANCELLED_PARAM, RETURN_PARAM);
        writeStorage(HANDOFF_STORAGE_KEY, null);
        if (!handoff) {
            return null;
        }

        if (cancelled) {
            const extension = extensionId ? await this.extensionPanel.openById(extensionId) : null;
            this.showStatus({ phase: 'cancelled', extension, handoff });
            return 'cancelled';
        }

        return this.finalize(handoff, extensionId);
    }

    /**
     * Show Finalizing and poll the hand-off until it completes, expires, or
     * the timeout leaves it to the webhook.
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
        const phase = status === 'completed' ? 'success' : status === 'failed' ? 'failed' : 'processing';
        const refreshed = status === 'completed' ? await this.extensionPanel.reload() : extension;
        if (status === 'completed') {
            this.registryContext.loadCounts();
        }
        // The context may still have been loading when Finalizing opened
        await this.registryContext.load();
        this.modalsManager.setOptions({
            phase,
            extension: refreshed ?? extension,
            organizationName: this.registryContext.organizationName,
            receiptUrl: result?.receipt_url ?? null,
            serviceStatus: result?.service_status ?? null,
            amount: result?.purchase?.amount_total ?? null,
            currency: result?.purchase?.currency ?? result?.currency ?? null,
            ...this.statusButtons(phase),
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
            case 'cancelled':
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
            organizationName: this.registryContext.organizationName,
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
        if ((phase === 'failed' || phase === 'cancelled') && extension) {
            return this.purchase(extension);
        }
    }
}
