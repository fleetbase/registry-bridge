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
 * Stand-in for the `abilities` service: everything is allowed unless a test
 * lists what is denied in `denied`.
 */
export class AbilitiesStub extends Service {
    denied = new Set();

    can(permission) {
        return !this.denied.has(permission);
    }

    cannot(permission) {
        return !this.can(permission);
    }
}

/**
 * Stand-in for `notifications`: records every message by type.
 */
export class NotificationsStub extends Service {
    messages = [];

    record(type, message) {
        this.messages.push({ type, message });
    }

    info(message) {
        this.record('info', message);
    }

    success(message) {
        this.record('success', message);
    }

    warning(message) {
        this.record('warning', message);
    }

    error(message) {
        this.record('error', message);
    }

    serverError(error) {
        this.record('serverError', error);
    }
}

/**
 * Stand-in for `currentUser`.
 */
export class CurrentUserStub extends Service {
    companyId = 'company_1';
    isAdmin = false;
    company = { name: 'Acme Fleet' };
    user = {};
    permissions = [];
}

/**
 * Stand-in for `socket`: records listeners and lets a test emit events.
 */
export class SocketStub extends Service {
    listeners = {};

    listen(channel, callback) {
        this.listeners[channel] = callback;
    }

    emit(channel, event) {
        this.listeners[channel]?.(event);
    }
}

/**
 * Stand-in for `modalsManager` with one modal at a time: `show` keeps the
 * component name and options, and the option helpers act on them.
 */
export class ModalsManagerStub extends Service {
    shown = [];
    current = null;
    closed = 0;

    show(component, options = {}) {
        this.current = { component, options: { ...options } };
        this.shown.push(this.current);
        return Promise.resolve(this);
    }

    get modals() {
        return this.current ? [this.current] : [];
    }

    getModalZIndex() {
        return 1060;
    }

    get options() {
        return this.current?.options ?? {};
    }

    getOption(key, defaultValue = null) {
        return this.options[key] ?? defaultValue;
    }

    setOption(key, value) {
        if (this.current) {
            this.current.options[key] = value;
        }
    }

    setOptions(options = {}) {
        if (this.current) {
            Object.assign(this.current.options, options);
        }
    }

    startLoading() {
        this.setOption('isLoading', true);
    }

    stopLoading() {
        this.setOption('isLoading', false);
    }

    invoke(fn, ...params) {
        const callable = this.options[fn];
        return typeof callable === 'function' ? callable(...params) : null;
    }

    confirm() {
        return this.options.confirm?.(this);
    }

    decline() {
        return this.options.decline?.(this);
    }

    done() {
        this.closed++;
        this.current = null;
        return Promise.resolve(true);
    }
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
