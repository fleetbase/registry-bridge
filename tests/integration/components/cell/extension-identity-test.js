import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { resolveResourceKey } from '@fleetbase/ember-ui/utils/resource-registry';

module('Integration | Component | cell/extension-identity', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        // The console builds the resource registry index at boot; build it here before the first render
        resolveResourceKey(this.owner, 'registry-extension');
    });

    test('shows the name with version and update badges and opens the panel', async function (assert) {
        const opened = [];
        this.owner.register(
            'service:extension-panel',
            class extends Service {
                open(extension) {
                    opened.push(extension.id);
                }
            }
        );
        this.set('row', {
            id: 'ext_1',
            name: 'Flespi Connector',
            icon_url: 'https://example.test/icon.png',
            installed_version: '1.8.0',
            latest_version: '2.0.0',
            update_available: true,
            self_managed: true,
        });

        await render(hbs`<Cell::ExtensionIdentity @row={{this.row}} />`);

        assert.dom('[data-test-identity-label]').hasText('Flespi Connector');
        assert.dom('[data-badge-key="version"]').hasText('v1.8.0');
        assert.dom('[data-badge-key="update"]').hasText('Update v2.0.0');

        this.set('row', { id: 'ext_2', name: 'Fleet Insights', meta: { install_blocked_code: 'hosting', install_blocked_reason: 'Cloud only.' } });
        assert.dom('[data-badge-key="unavailable"]').hasText('Not available here');
        this.set('row', { ...this.row, id: 'ext_1', name: 'Flespi Connector', installed_version: '1.8.0', latest_version: '2.0.0', update_available: true, meta: {} });

        await click('[data-test-identity-button]');
        assert.deepEqual(opened, ['ext_1']);
    });
});
