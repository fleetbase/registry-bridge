import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { tracked } from '@glimmer/tracking';

export default class ApplicationController extends Controller {
    @service registryContext;

    /**
     * Listing counts by category slug, taken when the categories load. Explore results embed each
     * extension's category (same id, `meta.extensions_count: null`), and ember-data merges that into
     * the sidebar's category records, so the live `meta` loses the count for every category in use.
     */
    @tracked categoryCounts = null;

    rememberCategoryCounts(categories) {
        const counts = {};
        for (const category of isArray(categories) ? categories : []) {
            counts[category.slug] = category.meta?.extensions_count ?? category.extensions_count;
        }
        this.categoryCounts = counts;
    }

    /** Installed count for the sidebar, with the updates waiting when there are any (`7 · 2↑`). */
    get installedCount() {
        const installed = this.registryContext.countOf('installed');
        const updates = this.registryContext.countOf('updates');
        if (installed === null) {
            return null;
        }
        return updates ? `${installed} · ${updates}↑` : String(installed);
    }

    get purchasedCount() {
        const purchased = this.registryContext.countOf('purchased');
        return purchased === null ? null : String(purchased);
    }

    /**
     * Categories for the sidebar, each with its listing count and the model
     * the category route takes (`{ slug }`, so the active item is the one in
     * the URL).
     */
    get sidebarCategories() {
        const categories = isArray(this.model) ? this.model : [];
        return categories.map((category) => {
            const remembered = this.categoryCounts?.[category.slug];
            const count = remembered ?? category.meta?.extensions_count ?? category.extensions_count;
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
