import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DevelopersPaymentsSettingsRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service fetch;
    @service intl;

    async beforeModel() {
        // Check if user has a Stripe Connect account before allowing access
        try {
            const { hasStripeConnectAccount } = await this.fetch.get('payments/has-stripe-connect-account', {}, { namespace: '~registry/v1' });
            if (!hasStripeConnectAccount) {
                this.notifications.warning(this.intl.t('registry-bridge.developers.payments.no-account-warning'));
                return this.hostRouter.transitionTo('console.extensions.payments.onboard');
            }
        } catch (error) {
            this.notifications.serverError(error);
            return this.hostRouter.transitionTo('console.extensions.payments');
        }
    }
}
