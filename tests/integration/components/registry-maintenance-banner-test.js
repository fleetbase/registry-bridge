import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | registry-maintenance-banner', function (hooks) {
    setupRenderingTest(hooks);

    test('shows the freeze message only while maintenance is on', async function (assert) {
        const context = this.owner.lookup('service:registry-context');

        await render(hbs`<RegistryMaintenanceBanner />`);
        assert.dom('[data-test-registry-maintenance]').doesNotExist();

        context.context = { maintenance: { enabled: true, message: 'Extensions are moving to the new registry until 14:00 UTC.' } };
        await render(hbs`<RegistryMaintenanceBanner />`);
        assert.dom('[data-test-registry-maintenance]').exists().hasAttribute('role', 'status');
        assert.dom('[data-test-registry-maintenance]').includesText('Extensions are paused while the registry is upgraded.');
        assert.dom('[data-test-registry-maintenance-message]').hasText('Extensions are moving to the new registry until 14:00 UTC.');
    });
});
