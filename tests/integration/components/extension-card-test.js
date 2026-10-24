import { module, test } from 'qunit';
import { setupRenderingTest, AbilitiesStub } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class PanelStub extends Service {
    opened = [];
    open(extension, options) {
        this.opened.push({ extension, options });
    }
}

class InstallerStub extends Service {
    calls = [];
    install(extension) {
        this.calls.push(['install', extension.id]);
    }
    update(extension) {
        this.calls.push(['update', extension.id]);
    }
}

const STATES = {
    free: { record: { payment_required: false }, price: 'Free', action: 'details' },
    paid: { record: { payment_required: true, price: 14900, currency: 'USD' }, price: '$149.00', action: 'details' },
    'on-sale': { record: { payment_required: true, price: 9900, sale_price: 7900, on_sale: true, currency: 'USD' }, price: '$79.00', action: 'details', badge: 'On sale' },
    installed: { record: { is_installed: true }, price: 'Free', action: 'none', badge: 'Installed' },
    update: { record: { is_installed: true, update_available: true, installed_version: '1.8.0', latest_version: '2.0.0' }, price: 'Free', action: 'update', badge: 'Update v2.0.0' },
    purchased: { record: { payment_required: true, price: 5900, is_purchased: true }, price: 'Purchased', action: 'install', badge: 'Ready to install' },
    unavailable: {
        record: { is_installable: false, meta: { hosting: 'cloud', install_blocked_code: 'hosting', install_blocked_reason: 'This extension is only available on Fleetbase Cloud.' } },
        price: 'Free',
        action: 'details',
        badge: 'Not available here',
    },
};

module('Integration | Component | extension-card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:extension-panel', PanelStub);
        this.owner.register('service:extension-installer', InstallerStub);
    });

    for (const [state, expected] of Object.entries(STATES)) {
        test(`renders the ${state} state`, async function (assert) {
            this.set('extension', {
                id: 'ext_1',
                public_id: 'ext_1',
                name: 'Pallet WMS',
                publisher_name: 'Fleetbase',
                category_name: 'Warehouse',
                description: 'Receiving and picking.',
                ...expected.record,
            });

            await render(hbs`<ExtensionCard @resource={{this.extension}} />`);

            assert.dom('[data-test-extension-card]').hasAttribute('data-state', state);
            assert.dom('[data-test-extension-card-price]').includesText(expected.price);
            assert.dom('[data-test-extension-card-action]').hasAttribute('data-test-extension-card-action', expected.action);
            if (expected.badge) {
                assert.dom('[data-test-extension-card-badges]').includesText(expected.badge);
            }
        });
    }

    test('the struck list price shows on sale', async function (assert) {
        this.set('extension', { id: 'ext_1', name: 'Customer Portal', ...STATES['on-sale'].record });
        await render(hbs`<ExtensionCard @resource={{this.extension}} />`);
        assert.dom('[data-test-extension-card-price-original]').hasText('$99.00');
    });

    test('the name opens the detail panel and the footer runs the state action', async function (assert) {
        this.set('extension', { id: 'ext_1', name: 'Flespi', ...STATES.update.record });
        await render(hbs`<ExtensionCard @resource={{this.extension}} />`);

        await click('[data-test-extension-card-name]');
        assert.strictEqual(this.owner.lookup('service:extension-panel').opened.length, 1);

        await click('[data-test-extension-card-action]');
        assert.deepEqual(this.owner.lookup('service:extension-installer').calls, [['update', 'ext_1']]);
    });

    test('the update action is disabled without the install permission', async function (assert) {
        this.owner.lookup('service:abilities').denied.add('registry-bridge install extension');
        this.set('extension', { id: 'ext_1', name: 'Flespi', ...STATES.update.record });
        await render(hbs`<ExtensionCard @resource={{this.extension}} />`);

        assert.dom('[data-test-extension-card-action]').isDisabled();
    });

    test('an unavailable listing shows why on the card', async function (assert) {
        this.set('extension', { id: 'ext_1', name: 'Fleet Insights', ...STATES.unavailable.record });
        await render(hbs`<ExtensionCard @resource={{this.extension}} />`);

        assert.dom('[data-test-extension-card-reason]').hasText('This extension is only available on Fleetbase Cloud.');
        assert.dom('[data-test-extension-card-badges]').includesText('Cloud only');
    });

    test('the description falls back to the subtitle', async function (assert) {
        this.set('extension', { id: 'ext_1', name: 'Hello', subtitle: 'A short subtitle' });
        await render(hbs`<ExtensionCard @resource={{this.extension}} />`);
        assert.dom('[data-test-extension-card-description]').hasText('A short subtitle');
    });
});
