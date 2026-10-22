import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | extension-modal-title', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the extension name, subtitle and publisher', async function (assert) {
        this.set('extension', { name: 'Fleet-Ops', subtitle: 'Fleet management', publisher_name: 'Fleetbase' });

        await render(hbs`<ExtensionModalTitle @extension={{this.extension}} />`);

        assert.dom('h1').hasText('Fleet-Ops');
        assert.dom(this.element).containsText('Fleet management');
        assert.dom(this.element).containsText('Published by Fleetbase');
    });

    test('it reads the extension from modal options when given', async function (assert) {
        this.set('options', { extension: { name: 'Storefront', subtitle: 'Commerce', publisher_name: 'Fleetbase' } });

        await render(hbs`<ExtensionModalTitle @options={{this.options}} />`);

        assert.dom('h1').hasText('Storefront');
    });
});
