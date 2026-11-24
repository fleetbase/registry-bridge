import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PurchasedRoute extends Route {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('registry-bridge list extension-purchase')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    /** Loaded by the controller so the page keeps its header and shows a skeleton, an error or the list. */
    model() {
        return { load: () => this.fetch.get('registry-extensions/purchased', {}, { namespace: '~registry/v1', normalizeToEmberData: true, modelType: 'registry-extension' }) };
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.load.perform();
    }
}
