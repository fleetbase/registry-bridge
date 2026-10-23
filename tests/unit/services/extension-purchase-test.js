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
        return { id, public_id: id, name: 'Acme Route Planner' };
    }
    async reload() {
        this.reloads++;
        return { id: 'ext_1', public_id: 'ext_1', name: 'Acme Route Planner', is_purchased: true };
    }
}

class ContextStub extends Service {
    countLoads = 0;
    loadCounts() {
        this.countLoads++;
    }
}

// The hand-off as the bridge describes it (ServiceCheckout::describe) for the recorded `complete` response
const COMPLETED = {
    status: 'completed',
    service_status: 'complete',
    handoff: 'chk_3tampcinhk',
    extension: { id: 'ext_qo76q0el02', slug: 'acme-route-planner', name: 'Acme Route Planner' },
    purchase: { id: 'pur_rqcusnwvoh', amount_total: 3190, currency: 'USD', receipt_url: 'https://pay.stripe.com/receipts/payment/bridge_test' },
    receipt_url: 'https://pay.stripe.com/receipts/payment/bridge_test',
    amount: 2900,
    currency: 'USD',
};

module('Unit | Service | extension-purchase', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:modals-manager', ModalsManagerStub);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:current-user', CurrentUserStub);
        this.owner.register('service:fetch', StubFetchService);
        this.owner.register('service:url-search-params', UrlSearchParamsStub);
        this.owner.register('service:extension-panel', PanelStub);
        this.owner.register('service:registry-context', ContextStub);
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
        const url = new URL(checkoutReturnUrl('https://fleet.example.test/extensions/telematics?pricing=paid&handoff=old&cancelled=1', 'ext_1'));

        assert.strictEqual(url.pathname, '/extensions/telematics');
        assert.strictEqual(url.searchParams.get('pricing'), 'paid');
        assert.strictEqual(url.searchParams.get('extension_id'), 'ext_1');
        assert.strictEqual(url.searchParams.get('purchase_return'), '1');
        assert.strictEqual(url.searchParams.get('handoff'), null, 'a stale hand-off is dropped');
        assert.strictEqual(url.searchParams.get('cancelled'), null);
    });

    test('a purchase starts a hosted checkout with return and cancel URLs and redirects to it', async function (assert) {
        this.fetch.responses['purchases/checkout'] = { url: 'https://checkout.stripe.com/c/pay/cs_test', handoff: 'chk_1', status: 'open' };

        await this.service.purchase({ id: 'ext_1', public_id: 'ext_1' });

        const { data } = this.fetch.calls[0];
        assert.strictEqual(this.fetch.calls[0].path, 'purchases/checkout');
        assert.strictEqual(data.extension, 'ext_1');
        assert.true(data.return_url.includes('purchase_return=1'));
        assert.strictEqual(data.cancel_url, data.return_url);
        assert.deepEqual(this.redirects, ['https://checkout.stripe.com/c/pay/cs_test']);
        assert.strictEqual(JSON.parse(window.sessionStorage.getItem(HANDOFF_STORAGE_KEY)).handoff, 'chk_1');
        assert.strictEqual(this.service.lastError, null);
    });

    test('a refused checkout keeps the service message for the panel and does not redirect', async function (assert) {
        this.fetch.responses['purchases/checkout'] = new Error('Acme Route Planner cannot be bought right now: its publisher has not finished setting up payouts.');

        const result = await this.service.purchase({ id: 'ext_1', public_id: 'ext_1' });

        assert.strictEqual(result, null);
        assert.deepEqual(this.redirects, []);
        assert.deepEqual(this.service.lastError, { extension: 'ext_1', message: 'Acme Route Planner cannot be bought right now: its publisher has not finished setting up payouts.' });
        assert.false(this.service.isStarting);
    });

    test('returning from Stripe shows Finalizing and polls the hand-off until it completes', async function (assert) {
        this.params.extension_id = 'ext_1';
        this.params.purchase_return = '1';
        this.params.handoff = 'chk_3tampcinhk';
        const statuses = [{ status: 'pending', service_status: 'open' }, { status: 'pending', service_status: 'open' }, COMPLETED];
        this.fetch.responses['purchases/chk_3tampcinhk'] = () => statuses.shift();

        const status = await this.service.resume();

        assert.strictEqual(status, 'completed');
        assert.strictEqual(this.fetch.calls.filter((call) => call.path === 'purchases/chk_3tampcinhk').length, 3);
        assert.strictEqual(this.modals.current.component, 'modals/extension-purchase-status');
        assert.strictEqual(this.modals.options.phase, 'success');
        assert.strictEqual(this.modals.options.receiptUrl, 'https://pay.stripe.com/receipts/payment/bridge_test');
        assert.strictEqual(this.modals.options.amount, 3190, 'the amount paid, tax included');
        assert.true(this.modals.options.extension.is_purchased, 'the panel extension is reloaded');
        assert.strictEqual(this.owner.lookup('service:registry-context').countLoads, 1, 'the sidebar counts refresh');
    });

    test('the hand-off falls back to session storage when Stripe drops it', async function (assert) {
        this.params.extension_id = 'ext_1';
        this.params.purchase_return = '1';
        window.sessionStorage.setItem(HANDOFF_STORAGE_KEY, JSON.stringify({ handoff: 'chk_stored', extension: 'ext_1' }));
        this.fetch.responses['purchases/chk_stored'] = COMPLETED;

        assert.strictEqual(await this.service.resume(), 'completed');
        assert.strictEqual(window.sessionStorage.getItem(HANDOFF_STORAGE_KEY), null);
    });

    test('an expired hand-off offers Try again', async function (assert) {
        this.params.purchase_return = '1';
        this.params.handoff = 'chk_2';
        this.fetch.responses['purchases/chk_2'] = { status: 'failed', service_status: 'expired' };

        assert.strictEqual(await this.service.resume(), 'failed');
        assert.strictEqual(this.modals.options.phase, 'failed');
        assert.strictEqual(this.modals.options.serviceStatus, 'expired');
        assert.strictEqual(this.modals.options.acceptButtonText, 'Try again');
    });

    test('a cancelled checkout says so without polling', async function (assert) {
        this.params.extension_id = 'ext_1';
        this.params.purchase_return = '1';
        this.params.handoff = 'chk_4';
        this.params.cancelled = '1';

        assert.strictEqual(await this.service.resume(), 'cancelled');
        assert.strictEqual(this.modals.options.phase, 'cancelled');
        assert.strictEqual(this.fetch.calls.length, 0);
    });

    test('a webhook that has not landed in time leaves the purchase processing', async function (assert) {
        this.service.pollTimeout = 5;
        this.params.handoff = 'chk_3';
        this.fetch.responses['purchases/chk_3'] = { status: 'pending', service_status: 'open' };

        assert.strictEqual(await this.service.resume(), 'pending');
        assert.strictEqual(this.modals.options.phase, 'processing');
    });

    test('nothing to resume without a return marker', async function (assert) {
        assert.strictEqual(await this.service.resume(), null);
        assert.strictEqual(this.modals.current, null);
    });
});
