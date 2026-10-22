import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/extension-uninstall', function (hooks) {
    setupRenderingTest(hooks);

    test('it names the extension being uninstalled', async function (assert) {
        this.set('options', { title: 'Uninstall', extension: { name: 'Fleet-Ops' } });

        await render(hbs`<Modals::ExtensionUninstall @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom(document.body).containsText('Proceed with Uninstall?');
        assert.dom(document.body).containsText('Do you really want to uninstall the Fleet-Ops?');
    });
});
