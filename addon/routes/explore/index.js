import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ExploreIndexRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    queryParams = {
        query: {
            refreshModel: true,
        },
    };

    beforeModel() {
        if (this.abilities.cannot('registry-bridge list extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    model({ query }) {
        return this.store.query('registry-extension', { explore: 1, query });
    }
}
