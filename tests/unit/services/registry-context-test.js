import { module, test } from 'qunit';
import { setupTest, StubFetchService } from 'dummy/tests/helpers';

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
