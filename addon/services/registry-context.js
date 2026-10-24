import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export const CONTEXT_NAMESPACE = '~registry/v1';

/**
 * What every console user needs to know about the Registry Service this
 * instance talks to (GET ~registry/v1/registry-extensions/context): its URL,
 * whether the instance is registered, the registry organization that owns
 * its licences, the publisher portal to link to, whether paid extensions
 * can be bought here and whether the cutover freeze is on. Also the sidebar counts
 * (GET ~registry/v1/registry-extensions/counts).
 */
export default class RegistryContextService extends Service {
    @service fetch;
    @tracked context = {};
    @tracked counts = {};
    @tracked loaded = false;
    #pending = null;
    #stopWatching = null;
    #countsLoadedAt = 0;

    get usesService() {
        return Boolean(this.context.service_url);
    }

    get registered() {
        return this.context.registered === true;
    }

    get publisherUrl() {
        return this.context.publisher_url || null;
    }

    get serviceUrl() {
        return this.context.service_url || null;
    }

    get checkoutAvailable() {
        return this.context.checkout_available === true;
    }

    get instanceKind() {
        return this.context.instance_kind ?? 'self_hosted';
    }

    /** The cutover freeze: installing, updating, uninstalling and buying are paused (`registry:maintenance on`). */
    get inMaintenance() {
        return this.context.maintenance?.enabled === true;
    }

    get maintenanceMessage() {
        return this.context.maintenance?.message || null;
    }

    /** The registry organization this instance is registered to; it owns the licences bought here, not the console company. */
    get organizationName() {
        return this.context.organization?.name || null;
    }

    countOf(key) {
        const value = this.counts?.[key];
        return Number.isFinite(Number(value)) && value !== null && value !== undefined ? Number(value) : null;
    }

    /**
     * Refresh the sidebar counts (installed, updates, purchased). Failures keep
     * the last counts; the sidebar then shows none.
     */
    async loadCounts() {
        this.#countsLoadedAt = Date.now();
        try {
            this.counts = (await this.fetch.get('registry-extensions/counts', {}, { namespace: CONTEXT_NAMESPACE })) ?? {};
        } catch {
            // counts are decoration only
        }
        return this.counts;
    }

    /**
     * Keep the sidebar counts current without a reload: refresh them when the
     * console tab becomes visible again and when the user moves between
     * extensions pages, at most once every `minInterval` ms. A refund or
     * revoke on the service, or an install from `flb`, then shows up in the
     * sidebar on its own. Safe to call more than once.
     */
    watchCounts(router, { doc = typeof document === 'undefined' ? null : document, minInterval = 15000 } = {}) {
        if (this.#stopWatching) {
            return;
        }

        const refresh = () => {
            if (Date.now() - this.#countsLoadedAt >= minInterval) {
                this.loadCounts();
            }
        };
        const onVisible = () => {
            if (doc.visibilityState !== 'hidden') {
                refresh();
            }
        };
        const onRoute = (transition) => {
            if (String(transition?.to?.name ?? '').startsWith('console.extensions')) {
                refresh();
            }
        };

        doc?.addEventListener('visibilitychange', onVisible);
        router?.on?.('routeDidChange', onRoute);
        this.#stopWatching = () => {
            doc?.removeEventListener('visibilitychange', onVisible);
            router?.off?.('routeDidChange', onRoute);
        };
    }

    willDestroy() {
        this.#stopWatching?.();
        this.#stopWatching = null;
        super.willDestroy(...arguments);
    }

    /**
     * Load once; later calls share the first request. A failed load leaves
     * the defaults (no service, not registered) and can be retried.
     */
    load() {
        if (this.loaded) {
            return Promise.resolve(this.context);
        }
        if (!this.#pending) {
            this.#pending = this.fetch
                .get('registry-extensions/context', {}, { namespace: CONTEXT_NAMESPACE })
                .then((context) => {
                    this.context = context ?? {};
                    this.loaded = true;
                    return this.context;
                })
                .catch(() => this.context)
                .finally(() => (this.#pending = null));
        }

        return this.#pending;
    }
}
