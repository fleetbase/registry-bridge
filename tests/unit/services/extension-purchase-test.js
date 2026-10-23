import { module, test } from 'qunit';
import { setupTest, CurrentUserStub, ModalsManagerStub, NotificationsStub, StubFetchService } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { HANDOFF_STORAGE_KEY, checkoutReturnUrl } from '@fleetbase/registry-bridge-engine/services/extension-purchase';

class UrlSearchParamsStub extends Service {
    params = {};
    get(name) {
        return this.params[name] ?? null;
    }
}

class PanelStub extends Service {
    opened = [];
    reloads = 0;
    async openById(id) {
        this.opened.push(id);
        return { id, public_id: id, name: 'Pallet WMS', source: 'registry-service' };
    }
    async reload() {
        this.reloads++;
        return { id: 'ext_1', public_id: 'ext_1', name: 'Pallet WMS', is_purchased: true };
    }
    open() {}
}

module('Unit | Service | extension-purchase', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:modals-manager', ModalsManagerStub);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:current-user', CurrentUserStub);
        this.owner.register('service:fetch', StubFetchService);
        this.owner.register('service:url-search-params', UrlSearchParamsStub);
        this.owner.register('service:extension-panel', PanelStub);
        this.service = this.owner.lookup('service:extension-purchase');
        this.service.pollInterval = 1;
        this.service.schedule = (fn) => setTimeout(fn, 0);
        this.redirects = [];
        this.service.redirect = (url) => this.redirects.push(url);
        this.fetch = this.owner.lookup('service:fetch');
        this.modals = this.owner.lookup('service:modals-manager');
        this.params = this.owner.lookup('service:url-search-params').params;
        window.sessionStorage.removeItem(HANDOFF_STORAGE_KEY);
    });

    hooks.afterEach(function () {
        window.sessionStorage.removeItem(HANDOFF_STORAGE_KEY);
    });

    test('the return URL keeps the page and adds the panel extension and a return marker', function (assert) {
        const url = new URL(checkoutReturnUrl('https://fleet.example.test/extensions/telematics?pricing=paid&purchase_handoff=old', 'ext_1'));

        assert.strictEqual(url.pathname, '/extensions/telematics');
        assert.strictEqual(url.searchParams.get('pricing'), 'paid');
        assert.strictEqual(url.searchParams.get('extension_id'), 'ext_1');
        assert.strictEqual(url.searchParams.get('purchase_return'), '1');
        assert.strictEqual(url.searchParams.get('purchase_handoff'), null);
    });

    test('a service listing starts a hosted checkout and redirects to it', async function (assert) {
        this.fetch.responses['purchases/checkout'] = { url: 'https://checkout.stripe.test/c/cs_1', handoff: 'hof_1' };

        await this.service.purchase({ id: 'ext_1', public_id: 'ext_1', source: 'registry-service' });

        assert.strictEqual(this.fetch.calls[0].path, 'purchases/checkout');
        assert.strictEqual(this.fetch.calls[0].data.extension, 'ext_1');
        assert.true(this.fetch.calls[0].data.return_url.includes('purchase_return=1'));
        assert.deepEqual(this.redirects, ['https://checkout.stripe.test/c/cs_1']);
        assert.strictEqual(JSON.parse(window.sessionStorage.getItem(HANDOFF_STORAGE_KEY)).handoff, 'hof_1');
    });

    test('a refused checkout is reported and nothing redirects', async function (assert) {
        this.fetch.responses['purchases/checkout'] = new Error('Purchasing through the registry service is not available yet.');

        const result = await this.service.purchase({ id: 'ext_1', source: 'registry-service' });

        assert.strictEqual(result, null);
        assert.deepEqual(this.redirects, []);
        assert.strictEqual(this.owner.lookup('service:notifications').messages[0].type, 'serverError');
    });

    test('returning from checkout shows Finalizing and polls the hand-off until it completes', async function (assert) {
        this.params.extension_id = 'ext_1';
        this.params.purchase_return = '1';
        window.sessionStorage.setItem(HANDOFF_STORAGE_KEY, JSON.stringify({ handoff: 'hof_1', extension: 'ext_1' }));
        const statuses = [{ status: 'pending' }, { status: 'pending' }, { status: 'completed', receipt_url: 'https://pay.stripe.test/r/1' }];
        this.fetch.responses['purchases/hof_1'] = () => statuses.shift();

        const status = await this.service.resume();

        assert.strictEqual(status, 'completed');
        assert.strictEqual(this.fetch.calls.filter((call) => call.path === 'purchases/hof_1').length, 3);
        assert.strictEqual(this.modals.current.component, 'modals/extension-purchase-status');
        assert.strictEqual(this.modals.options.phase, 'success');
        assert.strictEqual(this.modals.options.receiptUrl, 'https://pay.stripe.test/r/1');
        assert.true(this.modals.options.extension.is_purchased, 'the panel extension is reloaded');
        assert.strictEqual(window.sessionStorage.getItem(HANDOFF_STORAGE_KEY), null);
    });

    test('a hand-off in the URL wins and a failed purchase offers Try again', async function (assert) {
        this.params.extension_id = 'ext_1';
        this.params.purchase_handoff = 'hof_2';
        this.fetch.responses['purchases/hof_2'] = { status: 'failed', service_status: 'expired' };

        assert.strictEqual(await this.service.resume(), 'failed');
        assert.strictEqual(this.modals.options.phase, 'failed');
        assert.strictEqual(this.modals.options.serviceStatus, 'expired');
        assert.strictEqual(this.modals.options.acceptButtonText, 'Try again');
    });

    test('a webhook that has not landed in time leaves the purchase processing', async function (assert) {
        this.service.pollTimeout = 5;
        this.params.purchase_handoff = 'hof_3';
        this.fetch.responses['purchases/hof_3'] = { status: 'pending' };

        assert.strictEqual(await this.service.resume(), 'pending');
        assert.strictEqual(this.modals.options.phase, 'processing');
    });

    test('nothing to resume without a return marker', async function (assert) {
        assert.strictEqual(await this.service.resume(), null);
        assert.strictEqual(this.modals.current, null);
    });
});
