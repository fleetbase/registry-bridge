import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class ContextStub extends Service {
    countLoads = 0;
    organizationName = 'Registry Demo Buyer';
    loadCounts() {
        this.countLoads++;
    }
}

const ACTIVE = { name: 'Registry Demo Pro', is_installed: false, is_purchased: true, meta: { licence_state: 'active' } };
const REFUNDED = { name: 'Acme Route Planner', is_installed: true, is_purchased: false, meta: { licence_state: 'refunded', licence: { status: 'revoked', revoked_reason: 'refunded' } } };
const REVOKED = { name: 'Delisted Pro', is_installed: false, is_purchased: false, is_installable: false, meta: { licence_state: 'revoked' } };

module('Unit | Controller | purchased', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:registry-context', ContextStub);
    });

    test('refunded and revoked licences stay listed but are not ready to install, and the tab counts refresh', async function (assert) {
        const controller = this.owner.lookup('controller:purchased');
        controller.model = { load: async () => [ACTIVE, REFUNDED, REVOKED] };

        await controller.load.perform();

        assert.deepEqual(
            controller.extensions.map((row) => row.name),
            ['Registry Demo Pro', 'Acme Route Planner', 'Delisted Pro']
        );
        assert.strictEqual(controller.readyToInstall, 1, 'only the active licence counts as ready');
        assert.strictEqual(this.owner.lookup('service:registry-context').countLoads, 1);
    });

    test('the page names the registry organization that bought them, when known', function (assert) {
        const controller = this.owner.lookup('controller:purchased');
        assert.strictEqual(controller.organizationName, 'Registry Demo Buyer');

        this.owner.lookup('service:registry-context').organizationName = null;
        assert.strictEqual(controller.organizationName, null);
    });

    test('a failed load keeps the error and leaves the counts alone', async function (assert) {
        const controller = this.owner.lookup('controller:purchased');
        controller.model = { load: async () => Promise.reject(new Error('503')) };

        await controller.load.perform();

        assert.strictEqual(controller.error.message, '503');
        assert.strictEqual(this.owner.lookup('service:registry-context').countLoads, 0);
    });
});
