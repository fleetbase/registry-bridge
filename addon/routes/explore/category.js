import ExploreIndexRoute from './index';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

const refresh = { refreshModel: true };

export default class ExploreCategoryRoute extends ExploreIndexRoute {
    @service store;

    queryParams = {
        query: refresh,
        pricing: refresh,
        hosting: refresh,
        hide_installed: refresh,
        sort: refresh,
        page: refresh,
    };

    /** The sidebar transitions with `{ slug }`, the URL with the slug param; both end up here. */
    model(params) {
        return { ...params, slug: params.slug };
    }

    async afterModel(model) {
        this.categoryRecord = await this.findCategory(model?.slug);
    }

    setupController(controller, model) {
        controller.slug = model?.slug ?? null;
        controller.categoryRecord = this.categoryRecord;
        super.setupController(...arguments);
    }

    /**
     * Resolve the category for a slug from the categories the application route already loaded
     * (which come from the registry in use), falling back to a local lookup.
     */
    async findCategory(slug) {
        if (!slug) {
            return null;
        }

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
