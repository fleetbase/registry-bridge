import { module, test } from 'qunit';
import { setupRenderingTest, StubFetchService } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | extension-monetize-form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.fetch = this.owner.lookup('service:fetch');
    });

    test('it looks up the Stripe Connect account and shows the payment toggle when one exists', async function (assert) {
        this.fetch.responses['payments/has-stripe-connect-account'] = { hasStripeConnectAccount: true };
        this.set('extension', { payment_required: false });

        await render(hbs`<ExtensionMonetizeForm @extension={{this.extension}} />`);

        assert.strictEqual(this.fetch.calls.length, 1, 'one lookup request');
        assert.strictEqual(this.fetch.calls[0].path, 'payments/has-stripe-connect-account');
        assert.dom(this.element).containsText('Payment Details');
        assert.dom(this.element).containsText('Payment Required');
        assert.dom('[role="checkbox"]').exists('the payment toggle renders');
    });

    test('it hides the payment settings without a Stripe Connect account', async function (assert) {
        this.fetch.responses['payments/has-stripe-connect-account'] = { hasStripeConnectAccount: false };
        this.set('extension', { payment_required: false });

        await render(hbs`<ExtensionMonetizeForm @extension={{this.extension}} />`);

        assert.dom('[role="checkbox"]').doesNotExist('no payment toggle without an account');
        assert.dom(this.element).doesNotContainText('Payment Required');
    });
});
