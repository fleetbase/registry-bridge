import ExploreIndexController from './index';
import { tracked } from '@glimmer/tracking';

/**
 * One category (design B + D): the rail with every category and the filters,
 * scoped to the category in the URL.
 */
export default class ExploreCategoryController extends ExploreIndexController {
    queryParams = ['query', 'pricing', 'hosting', 'hide_installed', 'compatible', 'sort', 'page'];
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
        return Boolean(this.query) || ['pricing', 'hosting', 'hide_installed', 'compatible'].some((param) => Boolean(this[param]));
    }

    /** A category page always lists its extensions. */
    get isDiscover() {
        return false;
    }

    /** The rail: every category, the current one marked. */
    get railCategories() {
        return (this.categories ?? []).map((category) => ({
            name: category.name,
            slug: category.slug,
            icon: category.icon || 'shapes',
            current: category.slug === this.slug || category.id === this.slug,
        }));
    }
}
