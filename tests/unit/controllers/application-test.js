import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | application', function (hooks) {
    setupTest(hooks);

    test('sidebar categories carry their count and a slug model', function (assert) {
        const controller = this.owner.lookup('controller:application');
        controller.model = [
            { name: 'Telematics', slug: 'telematics', icon: 'satellite-dish', meta: { extensions_count: 6 } },
            { name: 'Finance', slug: 'finance', meta: { extensions_count: 0 } },
            { name: 'Warehouse', slug: 'warehouse' },
        ];

        assert.deepEqual(controller.sidebarCategories, [
            { name: 'Telematics', slug: 'telematics', icon: 'satellite-dish', model: { slug: 'telematics' }, count: '6' },
            { name: 'Finance', slug: 'finance', icon: undefined, model: { slug: 'finance' }, count: '0' },
            { name: 'Warehouse', slug: 'warehouse', icon: undefined, model: { slug: 'warehouse' }, count: null },
        ]);
    });
});
