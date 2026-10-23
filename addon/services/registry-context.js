import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export const CONTEXT_NAMESPACE = '~registry/v1';

/**
 * What every console user needs to know about the Registry Service this
 * instance talks to (GET ~registry/v1/registry-extensions/context): its URL,
 * whether the instance is registered, the registry organization that owns
 * its licences, the publisher portal to link to and whether paid extensions
 * can be bought here. Also the sidebar counts
 * (GET ~registry/v1/registry-extensions/counts).
 */
export default class RegistryContextService extends Service {
    @service fetch;
    @tracked context = {};
    @tracked counts = {};
    @tracked loaded = false;
    #pending = null;

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
        try {
            this.counts = (await this.fetch.get('registry-extensions/counts', {}, { namespace: CONTEXT_NAMESPACE })) ?? {};
        } catch {
            // counts are decoration only
        }
        return this.counts;
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
