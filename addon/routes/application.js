import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ApplicationRoute extends Route {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('registry-bridge see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    model() {
        return this.fetch.get('categories', {}, { namespace: '~registry/v1', normalizeToEmberData: true, modelType: 'category' });
    }

    // async setupController(controller) {
    //     super.setupController(...arguments);
    //     // controller.categories = await this.store.query('category', { for: 'extension_category', core_category: 1 });
    //     controller.categories = await this.fetch.get('categories', {}, { namespace: '~registry/v1', normalizeToEmberData: true, modelType: 'category' });
    // }
}
