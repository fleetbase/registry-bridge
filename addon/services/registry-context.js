import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export const CONTEXT_NAMESPACE = '~registry/v1';

/**
 * What every console user needs to know about the registry this instance
 * talks to (GET ~registry/v1/registry-extensions/context): whether it uses
 * the Registry Service, whether the instance is registered, the publisher
 * portal to link to and whether paid extensions can be bought here.
 */
export default class RegistryContextService extends Service {
    @service fetch;
    @tracked context = {};
    @tracked loaded = false;
    #pending = null;

    get usesService() {
        return this.context.uses_service === true;
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

    get facilitatorFee() {
        return Number(this.context.facilitator_fee ?? 10);
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
