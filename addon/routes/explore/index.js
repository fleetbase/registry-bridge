import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

const refresh = { refreshModel: true };

export default class ExploreIndexRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    queryParams = {
        query: refresh,
        category: refresh,
        pricing: refresh,
        hosting: refresh,
        hide_installed: refresh,
        sort: refresh,
        page: refresh,
    };

    beforeModel() {
        if (this.abilities.cannot('registry-bridge list extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    /** The catalog loads in the controller so the page renders skeletons and keeps its header while it does. */
    model(params) {
        return { ...params };
    }

    setupController(controller) {
        super.setupController(...arguments);
        const categories = this.modelFor('application');
        controller.categories = isArray(categories) ? categories : [];
        controller.load.perform();
    }
}
