import { setupApplicationTest as upstreamSetupApplicationTest, setupRenderingTest as upstreamSetupRenderingTest, setupTest as upstreamSetupTest } from 'ember-qunit';
import { setupIntl } from 'ember-intl/test-support';
import Service from '@ember/service';

// This file exists to provide wrappers around ember-qunit's
// test setup functions. This way, you can easily extend the setup that is
// needed per test type.

/**
 * Stand-in for the console's `hostRouter` service, which the host application
 * provides to engines at runtime but the dummy app does not have.
 */
export class HostRouterStub extends Service {
    currentRouteName = 'console.extensions.explore.index';
    transitions = [];
    refreshCalls = 0;

    transitionTo(...args) {
        this.transitions.push(args);
        return Promise.resolve();
    }

    replaceWith(...args) {
        return this.transitionTo(...args);
    }

    refresh() {
        this.refreshCalls++;
        return Promise.resolve();
    }

    urlFor() {
        return '/';
    }
}

/**
 * Stand-in for @fleetbase/ember-core's `fetch` service. Responses are looked up by
 * path in `responses`, falling back to `response`; every call is recorded in `calls`.
 */
export class StubFetchService extends Service {
    response = {};
    responses = {};
    calls = [];

    async request(path, method = 'GET', data = {}, options = {}) {
        this.calls.push({ path, method, data, options });

        const response = Object.prototype.hasOwnProperty.call(this.responses, path) ? this.responses[path] : this.response;
        if (response instanceof Error) {
            throw response;
        }

        return typeof response === 'function' ? response(data, options) : response;
    }

    get(path, query = {}, options = {}) {
        return this.request(path, 'GET', query, options);
    }

    post(path, data = {}, options = {}) {
        return this.request(path, 'POST', data, options);
    }

    put(path, data = {}, options = {}) {
        return this.request(path, 'PUT', data, options);
    }

    patch(path, data = {}, options = {}) {
        return this.request(path, 'PATCH', data, options);
    }

    delete(path, data = {}, options = {}) {
        return this.request(path, 'DELETE', data, options);
    }

    download() {
        return Promise.resolve();
    }

    jsonToModel(json) {
        return json;
    }

    get uploadFile() {
        return { perform: () => Promise.resolve() };
    }
}

/**
 * Replace `store.query` / `store.findAll` with a resolver so components that load
 * data on construction never hit the network.
 *
 * @param {Owner} owner the test owner
 * @param {Function|Array} resolver records, or `(modelName, query) => records`
 * @return {Array} the recorded calls, as `{ modelName, query }`
 */
export function stubStoreQuery(owner, resolver = []) {
    const store = owner.lookup('service:store');
    const calls = [];
    const resolve = (modelName, query) => {
        calls.push({ modelName, query });
        return Promise.resolve(typeof resolver === 'function' ? resolver(modelName, query) : resolver);
    };

    store.query = resolve;
    store.findAll = resolve;

    return calls;
}

/**
 * Register services the engine expects from the host console unless a test
 * already registered its own stub.
 */
function registerHostServices(hooks) {
    hooks.beforeEach(function () {
        if (!this.owner.hasRegistration('service:host-router')) {
            this.owner.register('service:host-router', HostRouterStub);
        }
    });
}

function setupApplicationTest(hooks, options) {
    upstreamSetupApplicationTest(hooks, options);
    setupIntl(hooks, 'en-us');
    registerHostServices(hooks);
}

function setupRenderingTest(hooks, options) {
    upstreamSetupRenderingTest(hooks, options);
    setupIntl(hooks, 'en-us');
    registerHostServices(hooks);
}

function setupTest(hooks, options) {
    upstreamSetupTest(hooks, options);
    setupIntl(hooks, 'en-us');
    registerHostServices(hooks);
}

export { setupApplicationTest, setupRenderingTest, setupTest };
