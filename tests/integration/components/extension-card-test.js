import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | extension-card', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the extension name and price badge', async function (assert) {
        this.set('extension', {
            id: 'extension_test',
            public_id: 'extension_test',
            name: 'Fleet-Ops',
            description: 'Fleet & Transport Management',
            payment_required: false,
            is_installed: false,
            updatedAt: 'January, 01 2026',
        });

        await render(hbs`<ExtensionCard @resource={{this.extension}} />`);

        assert.dom(this.element).containsText('Fleet-Ops');
        assert.dom(this.element).containsText('Free');
    });
});
