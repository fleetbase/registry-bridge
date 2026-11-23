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

    test('navigation items: Installed, Purchased, Explore with categories, then the publisher portal', function (assert) {
        const controller = this.owner.lookup('controller:application');
        const context = this.owner.lookup('service:registry-context');
        const hostRouter = this.owner.lookup('service:host-router');
        context.counts = { installed: 2, updates: 1, purchased: 0 };
        controller.model = [
            { name: 'Telematics', slug: 'telematics', icon: 'satellite-dish', meta: { extensions_count: 4 } },
            { name: 'Finance', slug: 'finance', meta: { extensions_count: 0 } },
        ];

        let items = controller.navigationItems;
        assert.deepEqual(
            items.map((item) => item.id),
            ['installed', 'purchased', 'explore'],
            'no publisher portal before the context names one'
        );
        assert.strictEqual(items[0].badge, '2 · 1↑');
        assert.strictEqual(items[0].visiblePermission, 'registry-bridge see extension-install');
        assert.strictEqual(items[1].badge, '0');
        assert.strictEqual(items[2].defaultRoute, 'console.extensions.explore.index');

        const [all, telematics, finance] = items[2].children;
        assert.strictEqual(all.route, 'console.extensions.explore.index');
        assert.strictEqual(telematics.label, 'Telematics');
        assert.strictEqual(telematics.icon, 'satellite-dish');
        assert.strictEqual(telematics.badge, '4');
        assert.strictEqual(telematics.route, undefined, 'categories share a route, so they navigate with onClick');
        assert.strictEqual(finance.icon, 'folder');
        assert.strictEqual(finance.badge, null, 'an empty category shows no badge');

        telematics.onClick();
        assert.deepEqual(hostRouter.transitions.at(-1), ['console.extensions.explore.category', 'telematics']);

        const route = 'console.extensions.explore.category';
        assert.true(telematics.activeWhen({ routeName: route, router: { currentRoute: { params: { slug: 'telematics' } } } }));
        assert.false(finance.activeWhen({ routeName: route, router: { currentRoute: { params: { slug: 'telematics' } } } }));
        assert.true(finance.activeWhen({ routeName: route, router: {}, currentURL: '/extensions/finance?sort=popular' }), 'falls back to the URL');
        assert.false(telematics.activeWhen({ routeName: 'console.extensions.explore.index', router: {} }));

        context.context = { publisher_url: 'https://registry.example.test' };
        items = controller.navigationItems;
        const portal = items.at(-1);
        assert.strictEqual(portal.id, 'publisher-portal');
        assert.strictEqual(portal.url, 'https://registry.example.test');
        assert.strictEqual(portal.target, '_blank');
    });
});
