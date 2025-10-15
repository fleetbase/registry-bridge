import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ExploreCategoryRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    queryParams = {
        query: {
            refreshModel: false,
        },
    };

    beforeModel() {
        if (this.abilities.cannot('registry-bridge list extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    async model({ slug, query }) {
        try {
            const category = await this.store.queryRecord('category', { slug, for: 'extension_category', core_category: 1, single: 1 });
            if (!category) return [];

            return this.store.query('registry-extension', { explore: 1, category: category.id, query: query });
        } catch {
            return [];
        }
    }
}
