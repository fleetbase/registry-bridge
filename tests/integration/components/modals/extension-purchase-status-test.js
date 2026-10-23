import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/extension-purchase-status', function (hooks) {
    setupRenderingTest(hooks);

    test('each phase after the checkout redirect', async function (assert) {
        this.set('options', { phase: 'finalizing', companyName: 'Acme Fleet', extension: { name: 'Pallet WMS' } });
        await render(hbs`<Modals::ExtensionPurchaseStatus @modalIsOpened={{true}} @options={{this.options}} />`);
        assert.dom('[data-test-purchase-status]').includesText('Finalizing purchase…');
        assert.dom('[data-test-purchase-status]').includesText('Acme Fleet');

        this.set('options', { phase: 'success', extension: { name: 'Pallet WMS' }, receiptUrl: 'https://pay.stripe.test/r/1' });
        assert.dom('[data-test-purchase-success]').includesText('Pallet WMS is yours');
        assert.dom('[data-test-purchase-success] a').hasAttribute('href', 'https://pay.stripe.test/r/1');

        this.set('options', { phase: 'failed', serviceStatus: 'expired' });
        assert.dom('[data-test-purchase-failed]').includesText('Stripe reported the checkout session as expired');

        this.set('options', { phase: 'processing' });
        assert.dom('[data-test-purchase-processing]').includesText('Payment is still being confirmed');
    });
});
