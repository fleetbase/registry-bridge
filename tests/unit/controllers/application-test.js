import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | application', function (hooks) {
    setupTest(hooks);

    test('Installed shows the updates waiting next to its count', function (assert) {
        const controller = this.owner.lookup('controller:application');
        const context = this.owner.lookup('service:registry-context');

        assert.strictEqual(controller.installedCount, null, 'no count before it loads');
        context.counts = { installed: 7, updates: 2, purchased: 3 };
        assert.strictEqual(controller.installedCount, '7 · 2↑');
        assert.strictEqual(controller.purchasedCount, '3');
        context.counts = { installed: 4, updates: 0, purchased: 0 };
        assert.strictEqual(controller.installedCount, '4');
        assert.strictEqual(controller.purchasedCount, '0');
    });

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

    test('category counts survive an embedded category without its count', function (assert) {
        const controller = this.owner.lookup('controller:application');
        const fleet = { name: 'Fleet', slug: 'fleet', meta: { extensions_count: 3 } };
        controller.model = [fleet];
        controller.rememberCategoryCounts(controller.model);

        // What ember-data does when an Explore result embeds the same category.
        fleet.meta = { source: 'registry-service', extensions_count: null };

        assert.strictEqual(controller.sidebarCategories[0].count, '3');
    });
});
