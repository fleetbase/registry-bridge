import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/purchase-price and cell/licence-state', function (hooks) {
    setupRenderingTest(hooks);

    test('the price column shows the amount paid, not status chips', async function (assert) {
        this.set('row', {
            payment_required: true,
            price: 2900,
            currency: 'USD',
            is_installed: false,
            is_purchased: true,
            meta: { licence_state: 'active', licence: { kind: 'paid', status: 'active', purchase: { amount_total: 3190, currency: 'USD' } } },
        });
        await render(hbs`<Cell::PurchasePrice @row={{this.row}} />`);

        assert.dom('[data-test-purchase-price]').includesText('31.90', 'the receipt total, tax included');
        assert.dom('[data-test-purchase-price]').doesNotHaveClass('line-through');

        this.set('row', { payment_required: true, price: 4900, currency: 'USD', meta: { licence_state: 'refunded', licence: { kind: 'paid', status: 'revoked', purchase: 'pur_1' } } });
        assert.dom('[data-test-purchase-price]').includesText('49.00', 'the listing price without a recorded receipt');
        assert.dom('[data-test-purchase-price]').hasClass('line-through', 'struck through once refunded');

        this.set('row', { payment_required: false, price: 0, meta: {} });
        assert.dom('[data-test-purchase-price]').hasText('-');
    });

    test('the licence column shows Active, Refunded or Revoked with the service reason', async function (assert) {
        this.set('row', { meta: { licence_state: 'active', licence: { kind: 'paid', status: 'active' } } });
        await render(hbs`<Cell::LicenceState @row={{this.row}} />`);
        assert.dom('[data-test-licence-state="active"]').includesText('Active');
        assert.dom('[data-test-licence-state]').doesNotHaveAttribute('data-test-licence-reason');

        this.set('row', { meta: { licence_state: 'refunded', licence: { kind: 'paid', status: 'revoked', revoked_reason: 'refunded' } } });
        assert.dom('[data-test-licence-state="refunded"]').includesText('Refunded');
        assert.dom('[data-test-licence-state]').hasAttribute('data-test-licence-reason', 'refunded');

        this.set('row', { meta: { licence_state: 'revoked', licence: { kind: 'paid', status: 'revoked', revoked_reason: 'disputed' } } });
        assert.dom('[data-test-licence-state="revoked"]').includesText('Revoked');
        assert.dom('[data-test-licence-state]').hasAttribute('data-test-licence-reason', 'disputed');

        this.set('row', { meta: { licence_state: 'revoked', licence: { kind: 'paid', status: 'revoked', revoked_reason: 'chargeback-v2' } } });
        assert.dom('[data-test-licence-state]').hasAttribute('data-test-licence-reason', 'other', 'an unknown reason reads as a plain revoke');

        this.set('row', { meta: {} });
        assert.dom('[data-test-licence-state]').doesNotExist();
    });
});
