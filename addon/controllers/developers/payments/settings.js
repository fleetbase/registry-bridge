import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { loadConnectAndInitialize } from '@stripe/connect-js';
import config from '../../../config/environment';

export default class DevelopersPaymentsSettingsController extends Controller {
    @service fetch;
    @service notifications;

    @tracked connectInstance;
    @tracked accountManagementComponent;
    @tracked isLoading = true;

    @action
    async setupStripe(element) {
        try {
            await this.initializeStripe();
            if (this.accountManagementComponent) {
                element.appendChild(this.accountManagementComponent);
            }
            this.isLoading = false;
        } catch (error) {
            this.notifications.serverError(error);
            this.isLoading = false;
        }
    }

    async fetchClientSecret() {
        try {
            const { clientSecret } = await this.fetch.post('payments/account-management-session', {}, { namespace: '~registry/v1' });
            return clientSecret;
        } catch (error) {
            this.notifications.serverError(error);
            return null;
        }
    }

    async initializeStripe() {
        if (this.connectInstance) {
            return;
        }

        this.connectInstance = loadConnectAndInitialize({
            publishableKey: config.stripe.publishableKey,
            fetchClientSecret: this.fetchClientSecret.bind(this),
            appearance: {
                overlays: 'dialog',
                variables: {
                    colorPrimary: '#635BFF',
                },
            },
        });

        this.accountManagementComponent = this.connectInstance.create('account-management');
    }
}
