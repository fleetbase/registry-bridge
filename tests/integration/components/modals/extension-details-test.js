import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import ExtensionDetailsModal from '@fleetbase/registry-bridge-engine/components/modals/extension-details';

module('Integration | Component | modals/extension-details', function (hooks) {
    setupRenderingTest(hooks);

    // @fleetbase/ember-ui ships a placeholder `modals/extension-details` component whose
    // `app/` re-export shadows the engine's in the dummy app (the console resolves engine
    // components from the engine's own namespace, so this only bites the test build).
    hooks.beforeEach(function () {
        this.owner.register('component:modals/extension-details', ExtensionDetailsModal);
    });

    test('it renders the extension details from the modal options', async function (assert) {
        this.set('options', {
            title: 'Fleet-Ops',
            extension: {
                name: 'Fleet-Ops',
                category_name: 'Logistics',
                description: 'Fleet & Transport Management',
                promotional_text: '<p>Promo</p>',
                current_bundle_version: '0.6.73',
                publisher_name: 'Fleetbase',
                screenshots: [],
            },
        });

        await render(hbs`<Modals::ExtensionDetails @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom(document.body).containsText('Logistics');
        assert.dom(document.body).containsText('Fleet & Transport Management');
        assert.dom(document.body).containsText('0.6.73');
        assert.dom(document.body).containsText('Fleetbase');
    });
});
