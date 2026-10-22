import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/create-registry-credentials', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders a password field', async function (assert) {
        this.set('options', { title: 'Create registry credentials', password: '' });

        await render(hbs`<Modals::CreateRegistryCredentials @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('input[type="password"]', document.body).exists('password input renders');
        assert.dom(document.body).containsText('You must authenticate using your password.');
    });
});
