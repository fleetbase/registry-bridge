import { module, test } from 'qunit';
import { setupTest, StubFetchService } from 'dummy/tests/helpers';

module('Unit | Service | registry-context', function (hooks) {
    setupTest(hooks);

    test('loads the context once and exposes the publisher portal and checkout flag', async function (assert) {
        this.owner.register('service:fetch', StubFetchService);
        const fetch = this.owner.lookup('service:fetch');
        fetch.responses['registry-extensions/context'] = {
            uses_service: true,
            registered: true,
            publisher_url: 'https://registry.example.test',
            checkout_available: false,
            facilitator_fee: 12,
        };
        const context = this.owner.lookup('service:registry-context');

        await Promise.all([context.load(), context.load()]);
        await context.load();

        assert.strictEqual(fetch.calls.length, 1);
        assert.strictEqual(context.publisherUrl, 'https://registry.example.test');
        assert.true(context.usesService);
        assert.true(context.registered);
        assert.false(context.checkoutAvailable);
        assert.strictEqual(context.facilitatorFee, 12);
    });

    test('a failed load keeps the defaults', async function (assert) {
        this.owner.register('service:fetch', StubFetchService);
        this.owner.lookup('service:fetch').response = new Error('offline');
        const context = this.owner.lookup('service:registry-context');

        await context.load();

        assert.false(context.loaded);
        assert.strictEqual(context.publisherUrl, null);
        assert.false(context.usesService);
    });
});
