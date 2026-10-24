import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { matchesQuery } from '@fleetbase/registry-bridge-engine/controllers/installed';

module('Unit | Controller | installed', function (hooks) {
    setupTest(hooks);

    test('search matches name, publisher, category and slug', function (assert) {
        const extension = { name: 'Flespi Connector', publisher_name: 'Fleetbase', category_name: 'Telematics', slug: 'flespi' };
        assert.true(matchesQuery(extension, ''));
        assert.true(matchesQuery(extension, 'flespi'));
        assert.true(matchesQuery(extension, 'TELEMATICS'));
        assert.false(matchesQuery(extension, 'warehouse'));
    });

    test('loads rows, filters them and counts updates', async function (assert) {
        const controller = this.owner.lookup('controller:installed');
        controller.model = { load: async () => [{ name: 'A', update_available: true }, { name: 'B' }] };

        await controller.load.perform();
        assert.strictEqual(controller.rows.length, 2);
        assert.strictEqual(controller.updatesAvailable, 1);

        controller.query = 'b';
        assert.deepEqual(
            controller.rows.map((row) => row.name),
            ['B']
        );
    });

    test('a failed load keeps the error for the page', async function (assert) {
        const controller = this.owner.lookup('controller:installed');
        controller.model = { load: async () => Promise.reject(new Error('503')) };

        await controller.load.perform();
        assert.strictEqual(controller.error.message, '503');
        assert.deepEqual(controller.extensions, []);
    });
});
