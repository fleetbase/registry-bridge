import Component from '@glimmer/component';

/**
 * The left rail of Discover and the category pages: "Only what works with my
 * Fleetbase", price, and every category (the current one marked). `@catalog`
 * is the Explore or category controller (compatible, pricing,
 * toggleCompatible, setPricing, railCategories, slug).
 *
 * @argument {Object} catalog
 */
export default class RegistryCatalogRailComponent extends Component {
    /** "All extensions" goes to Discover with nothing searched, filtered or sorted. */
    resetQuery = { query: null, category: null, pricing: null, hosting: null, hide_installed: null, compatible: null, sort: null, page: 1 };

    /** On Discover (no category in the URL) "All extensions" is the current item. */
    get allCurrent() {
        return !this.args.catalog?.slug;
    }
}
