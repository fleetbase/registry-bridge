import Component from '@glimmer/component';
import { action } from '@ember/object';

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

    /**
     * Keep the current category in view: on a phone the categories are one
     * scrolling row of chips (centre it); on desktop the rail scrolls on its
     * own (bring it into view inside the rail). Runs when the rail renders and
     * when the category changes.
     */
    @action revealCurrent(element) {
        window.requestAnimationFrame(() => {
            const current = element.querySelector('.is-current');
            if (!current) {
                return;
            }
            if (element.scrollWidth > element.clientWidth) {
                element.scrollLeft = Math.max(0, current.offsetLeft - element.offsetLeft - (element.clientWidth - current.offsetWidth) / 2);
                return;
            }
            const rail = element.closest('.rb-rail');
            if (rail && rail.scrollHeight > rail.clientHeight) {
                const top = current.getBoundingClientRect().top - rail.getBoundingClientRect().top + rail.scrollTop;
                if (top < rail.scrollTop || top + current.offsetHeight > rail.scrollTop + rail.clientHeight) {
                    rail.scrollTop = Math.max(0, top - rail.clientHeight / 2);
                }
            }
        });
    }

    /** On Discover (no category in the URL) "All extensions" is the current item. */
    get allCurrent() {
        return !this.args.catalog?.slug;
    }
}
