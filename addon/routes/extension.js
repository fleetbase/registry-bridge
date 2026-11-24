import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/** One extension's page (design: the A product page), by public id or slug. */
export default class ExtensionRoute extends Route {
    @service extensionPanel;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('registry-bridge list extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    model({ id }) {
        return { id };
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.tab = 'overview';
        controller.load.perform(model.id);
    }
}
