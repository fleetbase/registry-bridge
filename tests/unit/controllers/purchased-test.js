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

    test('the price column shows the amount and status stays in the install status column', function (assert) {
        const controller = this.owner.lookup('controller:purchased');
        const byLabel = Object.fromEntries(controller.columns.map((column) => [column.label, column]));

        assert.strictEqual(byLabel.Price.cellComponent, 'cell/purchase-price', 'no status chips under Price');
        assert.strictEqual(byLabel.Licence.cellComponent, 'cell/licence-state');
        assert.strictEqual(byLabel['Install status'].cellComponent, 'cell/extension-status');
        assert.strictEqual(byLabel['Install status'].variant, 'purchased');
    });

    test('refunded and revoked licences stay listed but are not ready to install, and the sidebar counts refresh', async function (assert) {
        const controller = this.owner.lookup('controller:purchased');
        controller.model = { load: async () => [ACTIVE, REFUNDED, REVOKED] };

        await controller.load.perform();

        assert.deepEqual(
            controller.rows.map((row) => row.name),
            ['Registry Demo Pro', 'Acme Route Planner', 'Delisted Pro']
        );
        assert.strictEqual(controller.readyToInstall, 1, 'only the active licence counts as ready');
        assert.strictEqual(this.owner.lookup('service:registry-context').countLoads, 1);

        const install = controller.columns.at(-1).actions.find((action) => action.icon === 'download');
        assert.true(install.isVisible(ACTIVE));
        assert.false(install.isVisible(REVOKED), 'a revoked licence offers no Install');
    });

    test('the empty state names the registry organization', function (assert) {
        const controller = this.owner.lookup('controller:purchased');
        assert.strictEqual(controller.organizationName, 'Registry Demo Buyer');

        this.owner.lookup('service:registry-context').organizationName = null;
        assert.strictEqual(controller.organizationName, 'your registry organization');
    });

    test('a failed load keeps the error and leaves the counts alone', async function (assert) {
        const controller = this.owner.lookup('controller:purchased');
        controller.model = { load: async () => Promise.reject(new Error('503')) };

        await controller.load.perform();

        assert.strictEqual(controller.error.message, '503');
        assert.strictEqual(this.owner.lookup('service:registry-context').countLoads, 0);
    });
});
