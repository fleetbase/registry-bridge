import { module, test } from 'qunit';
import { setupRenderingTest, StubFetchService, stubStoreQuery } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/select-extension-bundle', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
    });

    test('it loads the pending bundles for the extension and lists them', async function (assert) {
        const calls = stubStoreQuery(this.owner, [{ id: 'bundle_1', public_id: 'bundle_1', bundle_number: 3, version: '1.2.0', status: 'pending' }]);
        this.set('options', { title: 'Select bundle', extension: { id: 'ext_1', name: 'Fleet-Ops' } });

        await render(hbs`<Modals::SelectExtensionBundle @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.strictEqual(calls.length, 1, 'one bundle query');
        assert.strictEqual(calls[0].modelName, 'registry-extension-bundle');
        assert.deepEqual(calls[0].query, { extension_uuid: 'ext_1', status: 'pending' });
        assert.dom(document.body).containsText('1.2.0');
    });
});
