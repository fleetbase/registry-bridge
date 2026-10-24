import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

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
        const category = await this.findCategory(slug);
        this.category = category;

        try {
            return await this.store.query('registry-extension', { explore: 1, category: category ? category.id : slug, query });
        } catch {
            return [];
        }
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.category = this.category;
    }

    /**
     * Resolve the category for a slug from the categories the application route already loaded
     * (which come from the registry in use), falling back to a local lookup.
     */
    async findCategory(slug) {
        const categories = this.modelFor('application');
        if (isArray(categories)) {
            const category = categories.find((category) => category.slug === slug || category.id === slug);
            if (category) {
                return category;
            }
        }

        try {
            return await this.store.queryRecord('category', { slug, for: 'extension_category', core_category: 1, single: 1 });
        } catch {
            return null;
        }
    }
}
