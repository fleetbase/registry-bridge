import { module, test } from 'qunit';
import { setupTest, StubFetchService } from 'dummy/tests/helpers';
import { settled } from '@ember/test-helpers';

module('Unit | Service | registry-context', function (hooks) {
    setupTest(hooks);

    test('loads the context once and exposes the publisher portal and checkout flag', async function (assert) {
        this.owner.register('service:fetch', StubFetchService);
        const fetch = this.owner.lookup('service:fetch');
        fetch.responses['registry-extensions/context'] = {
            service_url: 'https://registry.example.test',
            registered: true,
            publisher_url: 'https://registry.example.test',
            checkout_available: false,
            instance_kind: 'cloud',
            organization: { id: 'org_9sz9sh8ivz', slug: 'registry-demo-buyer', name: 'Registry Demo Buyer' },
        };
        const context = this.owner.lookup('service:registry-context');

        await Promise.all([context.load(), context.load()]);
        await context.load();

        assert.strictEqual(fetch.calls.length, 1);
        assert.strictEqual(context.publisherUrl, 'https://registry.example.test');
        assert.true(context.usesService);
        assert.true(context.registered);
        assert.false(context.checkoutAvailable);
        assert.strictEqual(context.instanceKind, 'cloud');
        assert.strictEqual(context.organizationName, 'Registry Demo Buyer');
    });

    test('sidebar counts load separately and a failure keeps the last ones', async function (assert) {
        this.owner.register('service:fetch', StubFetchService);
        const fetch = this.owner.lookup('service:fetch');
        fetch.responses['registry-extensions/counts'] = { installed: 7, updates: 2, purchased: 3 };
        const context = this.owner.lookup('service:registry-context');

        await context.loadCounts();
        assert.deepEqual([context.countOf('installed'), context.countOf('updates'), context.countOf('purchased'), context.countOf('other')], [7, 2, 3, null]);

        fetch.responses['registry-extensions/counts'] = new Error('offline');
        await context.loadCounts();
        assert.strictEqual(context.countOf('installed'), 7);
    });

    test('the sidebar counts refresh when the tab is visible again and on extensions navigation, at most once per interval', async function (assert) {
        this.owner.register('service:fetch', StubFetchService);
        const fetch = this.owner.lookup('service:fetch');
        fetch.responses['registry-extensions/counts'] = { purchased: 1 };
        const context = this.owner.lookup('service:registry-context');

        const doc = new EventTarget();
        doc.visibilityState = 'visible';
        const listeners = {};
        const router = {
            on: (name, fn) => (listeners[name] = fn),
            off: (name) => delete listeners[name],
        };
        const countCalls = () => fetch.calls.filter((call) => call.path === 'registry-extensions/counts').length;

        context.watchCounts(router, { doc, minInterval: 0 });
        context.watchCounts(router, { doc, minInterval: 0 });

        // The refund lands on the service while the user is elsewhere
        fetch.responses['registry-extensions/counts'] = { purchased: 0 };
        doc.dispatchEvent(new Event('visibilitychange'));
        await Promise.resolve();
        assert.strictEqual(countCalls(), 1, 'one refresh per event even after watching twice');

        listeners.routeDidChange({ to: { name: 'console.extensions.installed' } });
        listeners.routeDidChange({ to: { name: 'console.fleet-ops.home' } });
        doc.visibilityState = 'hidden';
        doc.dispatchEvent(new Event('visibilitychange'));
        assert.strictEqual(countCalls(), 2, 'extensions navigation refreshes; other routes and a hidden tab do not');

        await context.loadCounts();
        assert.strictEqual(context.countOf('purchased'), 0);

        context.destroy();
        await settled();
        assert.notOk(listeners.routeDidChange, 'stops watching when destroyed');
    });

    test('watching respects the minimum interval between refreshes', async function (assert) {
        this.owner.register('service:fetch', StubFetchService);
        const fetch = this.owner.lookup('service:fetch');
        fetch.responses['registry-extensions/counts'] = { purchased: 1 };
        const context = this.owner.lookup('service:registry-context');
        const doc = new EventTarget();
        doc.visibilityState = 'visible';

        await context.loadCounts();
        context.watchCounts(null, { doc, minInterval: 60000 });
        doc.dispatchEvent(new Event('visibilitychange'));

        assert.strictEqual(fetch.calls.filter((call) => call.path === 'registry-extensions/counts').length, 1, 'no second request inside the interval');
    });

    test('a failed load keeps the defaults', async function (assert) {
        this.owner.register('service:fetch', StubFetchService);
        this.owner.lookup('service:fetch').response = new Error('offline');
        const context = this.owner.lookup('service:registry-context');

        await context.load();

        assert.false(context.loaded);
        assert.strictEqual(context.publisherUrl, null);
        assert.false(context.usesService);
        assert.strictEqual(context.organizationName, null);
    });
});
