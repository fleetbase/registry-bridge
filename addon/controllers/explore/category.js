import ExploreIndexController from './index';
import { tracked } from '@glimmer/tracking';

/**
 * One category of Explore (canvas C3): the same catalog, facets and layout,
 * scoped to the category in the URL.
 */
export default class ExploreCategoryController extends ExploreIndexController {
    queryParams = ['query', 'pricing', 'hosting', 'hide_installed', 'sort', 'page', 'layout'];
    @tracked slug = null;
    @tracked categoryRecord = null;

    get categoryFilter() {
        return this.categoryRecord?.id ?? this.slug;
    }

    get title() {
        const categoryName = this.categoryRecord?.name;
        return categoryName ? this.intl.t('registry-bridge.explore.category-title', { categoryName }) : this.intl.t('registry-bridge.explore.title');
    }

    get hasFilters() {
        return Boolean(this.query) || ['pricing', 'hosting', 'hide_installed'].some((param) => Boolean(this[param]));
    }
}
