import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ApplicationController extends Controller {
    @service registryContext;

    /**
     * Categories for the sidebar, each with its listing count and the model
     * the category route takes (`{ slug }`, so the active item is the one in
     * the URL).
     */
    get sidebarCategories() {
        const categories = isArray(this.model) ? this.model : [];
        return categories.map((category) => {
            const count = category.meta?.extensions_count ?? category.extensions_count;
            return {
                name: category.name,
                slug: category.slug,
                icon: category.icon,
                model: { slug: category.slug },
                count: Number.isFinite(Number(count)) && count !== null && count !== undefined ? String(count) : null,
            };
        });
    }
}
