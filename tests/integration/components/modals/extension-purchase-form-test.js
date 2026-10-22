import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/extension-purchase-form', function (hooks) {
    setupRenderingTest(hooks);

    test('it hands the checkout container to the options callback', async function (assert) {
        let inserted;
        this.set('options', { title: 'Purchase', checkoutElementInserted: (el) => (inserted = el) });

        await render(hbs`<Modals::ExtensionPurchaseForm @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.ok(inserted, 'callback called with the element');
        assert.strictEqual(inserted.id, 'checkout', 'the checkout mount point is passed');
    });
});
