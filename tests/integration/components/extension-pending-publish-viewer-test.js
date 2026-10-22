import { module, test } from 'qunit';
import { setupRenderingTest, StubFetchService, stubStoreQuery } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | extension-pending-publish-viewer', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
    });

    test('it queries approved extensions and lists them', async function (assert) {
        const calls = stubStoreQuery(this.owner, [
            { id: 'ext_1', name: 'Fleet-Ops', description: 'Fleet management', status: 'approved' },
            { id: 'ext_2', name: 'Storefront', description: 'Commerce', status: 'approved' },
        ]);

        await render(hbs`<ExtensionPendingPublishViewer />`);

        assert.strictEqual(calls.length, 1, 'one store query');
        assert.strictEqual(calls[0].modelName, 'registry-extension');
        assert.deepEqual(calls[0].query, { status: 'approved', admin: 1 });
        assert.dom(this.element).containsText('Extensions Pending Publish');
        assert.dom(this.element).containsText('Fleet-Ops');
        assert.dom(this.element).containsText('Storefront');
    });

    test('it shows the empty state when nothing is pending', async function (assert) {
        stubStoreQuery(this.owner, []);

        await render(hbs`<ExtensionPendingPublishViewer />`);

        assert.dom(this.element).containsText('No extensions awaiting publish');
    });
});
