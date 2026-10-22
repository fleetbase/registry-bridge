import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/confirm-extension-purchase', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the loading message from the modal options', async function (assert) {
        this.set('options', { title: 'Finalizing Purchase', loadingMessage: 'Completing purchase do not refresh or exit window...' });

        await render(hbs`<Modals::ConfirmExtensionPurchase @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom(document.body).containsText('Completing purchase do not refresh or exit window...');
    });
});
