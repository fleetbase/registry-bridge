import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/self-managed-install-instructions', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the CLI instructions', async function (assert) {
        this.set('options', { title: 'Install Extension to Self-Hosted Instance' });

        await render(hbs`<Modals::SelfManagedInstallInstructions @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom(document.body).containsText('Begin the installation from the base directory of your Fleetbase instance');
        assert.dom('ol.self-managed-install-instructions', document.body).exists();
    });
});
