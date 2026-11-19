import { module, test } from 'qunit';
import { setupRenderingTest, AbilitiesStub, CurrentUserStub, stubStoreQuery } from 'dummy/tests/helpers';
import { click, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

const listings = [
    { id: 'ext_1', public_id: 'ext_1', name: 'Samsara Telematics', publisher_name: 'Fleetbase', category_name: 'Telematics', payment_required: false, is_installed: true },
    { id: 'ext_2', public_id: 'ext_2', name: 'Pallet WMS', publisher_name: 'Fleetbase', category_name: 'Warehouse', payment_required: true, price: 14900, currency: 'USD' },
];

function page(records, total = records.length) {
    const result = [...records];
    result.meta = { total, current_page: 1, last_page: 1, per_page: 24 };
    return result;
}

module('Integration | Component | explore-catalog', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:current-user', CurrentUserStub);
        this.catalog = this.owner.lookup('controller:explore/index');
    });

    test('shows skeletons while the catalog loads, then the cards', async function (assert) {
        let resolve;
        stubStoreQuery(this.owner, () => new Promise((done) => (resolve = done)));
        this.catalog.load.perform();

        await render(hbs`<ExploreCatalog @catalog={{this.catalog}} />`);
        assert.dom('[data-test-explore-loading]').exists();

        resolve(page(listings));
        await settled();
        assert.dom('[data-test-explore-loading]').doesNotExist();
        assert.dom('[data-test-extension-card]').exists({ count: 2 });
        assert.dom('[data-test-explore-header]').includesText('2 extensions');
    });

    test('switches between the grid and the table', async function (assert) {
        stubStoreQuery(this.owner, page(listings));
        await this.catalog.load.perform();
        await render(hbs`<ExploreCatalog @catalog={{this.catalog}} />`);

        await click('[data-test-explore-layout="table"]');
        assert.dom('[data-test-explore-table]').exists();
        assert.dom('[data-test-explore-table] [data-test-identity-label]').exists({ count: 2 });
        assert.dom('[data-test-explore-table] [data-test-extension-status]').exists({ count: 2 });

        await click('[data-test-explore-layout="grid"]');
        assert.dom('[data-test-explore-grid]').exists();
    });

    test('an empty registry explains where extensions come from', async function (assert) {
        stubStoreQuery(this.owner, page([]));
        await this.catalog.load.perform();
        await render(hbs`<ExploreCatalog @catalog={{this.catalog}} />`);

        assert.dom('[data-test-explore-empty]').includesText('No extensions are published to this registry yet');
        assert.dom('[data-test-explore-empty]').doesNotIncludeText('Check registry config', 'only admins get the config shortcut');
    });

    test('no results for a search or facet offers to clear them', async function (assert) {
        stubStoreQuery(this.owner, page([]));
        this.catalog.query = 'geofence';
        this.catalog.pricing = 'paid';
        await this.catalog.load.perform();
        await render(hbs`<ExploreCatalog @catalog={{this.catalog}} />`);

        assert.dom('[data-test-explore-no-results]').includesText('No extensions match “geofence”');
        assert.dom('[data-test-explore-facet="pricing"]').includesText('Paid');

        await click('[data-test-explore-no-results] .next-table-empty-state-primary-action');
        assert.strictEqual(this.catalog.query, null);
        assert.strictEqual(this.catalog.pricing, null);
    });

    test('a registry error stays on screen with Retry', async function (assert) {
        let calls = 0;
        stubStoreQuery(this.owner, () => {
            calls++;
            return calls === 1 ? Promise.reject(new Error('timeout')) : Promise.resolve(page(listings));
        });
        await this.catalog.load.perform();
        await render(hbs`<ExploreCatalog @catalog={{this.catalog}} />`);

        assert.dom('[data-test-explore-error]').includesText('The registry did not respond');
        await click('[data-test-explore-retry]');
        assert.dom('[data-test-extension-card]').exists({ count: 2 });
    });

    test('facets and sort become registry request parameters', function (assert) {
        Object.assign(this.catalog, { query: 'route', category: 'cat_1', pricing: 'free', hosting: 'both', hide_installed: true, sort: 'newest', page: 2 });

        assert.deepEqual(this.catalog.requestParams, {
            explore: 1,
            query: 'route',
            category: 'cat_1',
            pricing: 'free',
            hosting: 'both',
            hide_installed: 1,
            sort: 'newest',
            page: 2,
            limit: 24,
        });

        this.catalog.setSort('popular');
        assert.strictEqual(this.catalog.sort, null, 'the default sort is not sent');
        this.catalog.clearFacet('hosting');
        assert.strictEqual(this.catalog.hosting, null);
        assert.strictEqual(this.catalog.page, 1);
    });

    test('an unregistered instance is told once that it can browse but not install', async function (assert) {
        stubStoreQuery(this.owner, page(listings));
        const context = this.owner.lookup('service:registry-context');
        context.context = { service_url: 'https://registry.example.test', registered: false };
        context.loaded = true;
        await this.catalog.load.perform();
        await render(hbs`<ExploreCatalog @catalog={{this.catalog}} />`);

        assert.dom('[data-test-explore-registration]').includesText('not registered with the registry service');
        assert.dom('[data-test-explore-registration]').includesText('Ask an admin');
        assert.dom('[data-test-extension-card]').exists({ count: 2 }, 'the catalog still shows');

        context.context = { ...context.context, registered: true };
        await settled();
        assert.dom('[data-test-explore-registration]').doesNotExist();
    });

    test('the hosting facet offers each kind', function (assert) {
        const hosting = this.catalog.facetColumns.find((column) => column.filterParam === 'hosting');
        assert.deepEqual(
            hosting.filterOptions.map((option) => option.value),
            ['self_hosted', 'cloud', 'both']
        );
    });
});
