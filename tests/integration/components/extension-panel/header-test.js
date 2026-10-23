import { module, test } from 'qunit';
import { setupRenderingTest, AbilitiesStub, CurrentUserStub } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

class InstallerStub extends Service {
    calls = [];
    install(extension) {
        this.calls.push(['install', extension.id]);
    }
    update(extension) {
        this.calls.push(['update', extension.id]);
    }
    uninstall(extension) {
        this.calls.push(['uninstall', extension.id]);
    }
}

class PurchaseStub extends Service {
    calls = [];
    @tracked lastError = null;
    isStarting = false;
    purchase(extension) {
        this.calls.push(extension.id);
        this.lastError = { extension: extension.public_id, message: 'Acme Route Planner cannot be bought right now: its publisher has not finished setting up payouts.' };
    }
}

const base = {
    id: 'ext_1',
    public_id: 'ext_1',
    name: 'Pallet WMS',
    source: 'registry-service',
    publisher_name: 'Fleetbase',
    category_name: 'Warehouse',
    latest_version: '0.9.1',
    is_installable: true,
};
const meta = (extra = {}) => ({ source: 'registry-service', ...extra });

module('Integration | Component | extension-panel/header', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:current-user', CurrentUserStub);
        this.owner.register('service:extension-installer', InstallerStub);
        this.owner.register('service:extension-purchase', PurchaseStub);
        this.renderHeader = async (extension) => {
            this.set('extension', { ...base, ...extension });
            await render(hbs`<ExtensionPanel::Header @resource={{this.extension}} />`);
        };
    });

    test('install', async function (assert) {
        await this.renderHeader({ meta: meta() });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'install');
        await click('[data-test-cta-button]');
        assert.deepEqual(this.owner.lookup('service:extension-installer').calls, [['install', 'ext_1']]);
    });

    test('purchase when the service offers checkout', async function (assert) {
        await this.renderHeader({
            payment_required: true,
            price: 14900,
            currency: 'USD',
            is_installable: false,
            meta: meta({ install_blocked_code: 'licence_required', purchase_available: true }),
        });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'purchase');
        assert.dom('[data-test-cta-button]').hasText('Purchase for $149.00');
        await click('[data-test-cta-button]');
        assert.deepEqual(this.owner.lookup('service:extension-purchase').calls, ['ext_1']);
        assert
            .dom('[data-test-cta-purchase-error]')
            .hasText('Acme Route Planner cannot be bought right now: its publisher has not finished setting up payouts.', 'a refusal is shown on the panel');
    });

    test('paid without checkout is blocked with the service reason', async function (assert) {
        await this.renderHeader({
            payment_required: true,
            price: 14900,
            is_installable: false,
            meta: meta({ install_blocked_code: 'licence_required', install_blocked_reason: 'Purchasing through the registry service is not available yet.' }),
        });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'blocked');
        assert.dom('[data-test-cta-button]').isDisabled();
        assert.dom('[data-test-cta-reason]').hasText('Purchasing through the registry service is not available yet.');
    });

    test('installed, with uninstall for users who may', async function (assert) {
        await this.renderHeader({ is_installed: true, installed_version: '0.9.1', meta: meta() });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'installed');
        assert.dom('[data-test-cta-installed]').includesText('Installed v0.9.1');
        await click('[data-test-cta-uninstall]');
        assert.deepEqual(this.owner.lookup('service:extension-installer').calls, [['uninstall', 'ext_1']]);
    });

    test('update', async function (assert) {
        await this.renderHeader({ is_installed: true, update_available: true, installed_version: '0.8.4', meta: meta() });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'update');
        assert.dom('[data-test-cta-button]').hasText('Update to v0.9.1');
        assert.dom('[data-test-cta]').includesText('Installed v0.8.4. Updating rebuilds the console.');
    });

    test('unauthorized names the permission', async function (assert) {
        this.owner.lookup('service:abilities').denied.add('registry-bridge install extension');
        await this.renderHeader({ meta: meta() });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'unauthorized');
        assert.dom('[data-test-cta-permission]').hasText('You need “registry-bridge install extension”. Ask an organisation admin.');
        assert.dom('[data-test-cta-uninstall]').doesNotExist();
    });

    test('author view', async function (assert) {
        await this.renderHeader({ is_author: true, meta: meta() });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'author');
        assert.dom('[data-test-cta]').includesText('You publish this extension');
    });

    test('registration required, with the shortcut for admins only', async function (assert) {
        await this.renderHeader({
            is_installable: false,
            meta: meta({ install_blocked_code: 'not_registered', install_blocked_reason: 'Register this instance with the registry service to install extensions.' }),
        });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'registration-required');
        assert.dom('[data-test-cta-button]').isDisabled();
        assert.dom('[data-test-cta-reason]').includesText('Ask an admin');

        this.owner.lookup('service:current-user').isAdmin = true;
        await this.renderHeader({ is_installable: false, meta: meta({ install_blocked_code: 'not_registered' }) });
        assert.dom('[data-test-cta-button]').isNotDisabled();
        await click('[data-test-cta-button]');
        assert.deepEqual(this.owner.lookup('service:host-router').transitions.at(-1), ['console.admin.virtual', 'extension-registry', { queryParams: { view: 'registry-config' } }]);
    });

    test('blocked by hosting', async function (assert) {
        await this.renderHeader({ is_installable: false, meta: meta({ install_blocked_code: 'hosting', install_blocked_reason: 'This extension is only available on Fleetbase Cloud.' }) });
        assert.dom('[data-test-cta]').hasAttribute('data-test-cta', 'blocked');
        assert.dom('[data-test-cta-reason]').hasText('This extension is only available on Fleetbase Cloud.');
    });
});
