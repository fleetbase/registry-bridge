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

    test('it flags an available update from the registry service', async function (assert) {
        this.set('extension', {
            id: 'ext_lx52k33erf',
            public_id: 'ext_lx52k33erf',
            name: 'Registry Demo Hello',
            payment_required: false,
            is_installed: true,
            installed_version: '1.0.0',
            latest_version: '1.1.0',
            update_available: true,
            source: 'registry-service',
        });

        await render(hbs`<ExtensionCard @resource={{this.extension}} />`);

        assert.dom('[data-test-update-available]').containsText('Update 1.1.0');
    });
});
