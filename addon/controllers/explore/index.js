import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task, timeout, all } from 'ember-concurrency';
import { isVerified } from '../../utils/store-model';

export const FACET_PARAMS = ['pricing', 'hosting', 'hide_installed', 'compatible'];
export const SORTS = ['popular', 'newest', 'updated', 'name'];
export const PAGE_SIZE = 24;
/** How many extensions each Discover shelf shows. */
export const SHELF_SIZE = 8;

/**
 * Explore (canvas C1): the service catalog on `Layout::Resource::CardsGrid`
 * or a table, with facets (category, pricing, hosting, hide installed), sort,
 * search and paging in query params. The category page extends this.
 */
export default class ExploreIndexController extends Controller {
    @service store;
    @service intl;
    @service hostRouter;
    @service currentUser;
    @service registryContext;
    @service filters;
    @service storeView;

    queryParams = ['query', 'category', 'pricing', 'hosting', 'hide_installed', 'compatible', 'sort', 'page', 'layout'];
    @tracked compatible = null;
    @tracked featured = [];
    @tracked shelves = [];
    @tracked query = null;
    @tracked category = null;
    @tracked pricing = null;
    @tracked hosting = null;
    @tracked hide_installed = null;
    @tracked sort = null;
    @tracked page = 1;
    @tracked layout = 'grid';

    @tracked extensions = [];
    @tracked categories = [];
    @tracked error = null;

    /** The category filter sent to the registry: a category id or slug. */
    get categoryFilter() {
        return this.category;
    }

    get title() {
        return this.intl.t('registry-bridge.explore.title');
    }

    get isTable() {
        return this.layout === 'table';
    }

    get isLoading() {
        return this.load.isRunning;
    }

    get total() {
        return this.extensions?.meta?.total ?? this.extensions?.length ?? 0;
    }

    get hasFilters() {
        return Boolean(this.query) || Boolean(this.category) || FACET_PARAMS.some((param) => Boolean(this[param]));
    }

    get isEmptyCatalog() {
        return !this.isLoading && !this.error && this.extensions.length === 0 && !this.hasFilters;
    }

    get isNoResults() {
        return !this.isLoading && !this.error && this.extensions.length === 0 && this.hasFilters;
    }

    get canConfigureRegistry() {
        return this.currentUser.isAdmin === true;
    }

    get registryHost() {
        // Until the context arrives (or when it failed) the URL is unknown: never name a default host.
        return this.registryContext.serviceUrl ?? this.intl.t('registry-bridge.explore.this-registry');
    }

    get requestParams() {
        const params = {
            explore: 1,
            query: this.query,
            category: this.categoryFilter,
            pricing: this.pricing,
            hosting: this.hosting,
            hide_installed: this.hide_installed ? 1 : null,
            compatible: this.compatible ? 1 : null,
            sort: this.sort,
            page: this.page,
            limit: PAGE_SIZE,
        };

        return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== ''));
    }

    get sortOptions() {
        return SORTS.map((value) => ({ value, label: this.intl.t(`registry-bridge.explore.sort.${value}`) }));
    }

    get pricingOptions() {
        return ['free', 'paid'].map((value) => ({ value, label: this.intl.t(`registry-bridge.explore.pricing.${value}`) }));
    }

    get hostingOptions() {
        return ['self_hosted', 'cloud', 'both'].map((value) => ({ value, label: this.intl.t(`registry-bridge.explore.hosting.${value}`) }));
    }

    /** Explore lists everything; without a registration nothing can be installed, so say so once above the list. */
    get needsRegistration() {
        return this.registryContext.loaded && !this.registryContext.registered;
    }

    get categoryOptions() {
        return (this.categories ?? []).map((category) => ({ value: category.id, label: category.name }));
    }

    /** Facet definitions for `FiltersPicker`. */
    get facetColumns() {
        const t = (key) => this.intl.t(`registry-bridge.explore.filters.${key}`);
        const columns = [];
        if (this.queryParams.includes('category')) {
            columns.push({ label: t('category'), filterable: true, filterParam: 'category', filterComponent: 'filter/select', filterOptions: this.categoryOptions });
        }
        columns.push({ label: t('pricing'), filterable: true, filterParam: 'pricing', filterComponent: 'filter/select', filterOptions: this.pricingOptions });
        columns.push({ label: t('hosting'), filterable: true, filterParam: 'hosting', filterComponent: 'filter/select', filterOptions: this.hostingOptions });
        columns.push({ label: t('hide-installed'), filterable: true, filterParam: 'hide_installed', filterComponent: 'filter/checkbox' });

        return columns;
    }

    /** Active facets as removable chips. */
    get activeFacets() {
        const t = (key) => this.intl.t(`registry-bridge.explore.filters.${key}`);
        const facets = [];
        if (this.queryParams.includes('category') && this.category) {
            const category = (this.categories ?? []).find((record) => record.id === this.category || record.slug === this.category);
            facets.push({ param: 'category', label: t('category'), value: category?.name ?? this.category });
        }
        if (this.pricing) {
            facets.push({ param: 'pricing', label: t('pricing'), value: this.pricingOptions.find((option) => option.value === this.pricing)?.label ?? this.pricing });
        }
        if (this.hosting) {
            facets.push({ param: 'hosting', label: t('hosting'), value: this.hostingOptions.find((option) => option.value === this.hosting)?.label ?? this.hosting });
        }
        if (this.hide_installed) {
            facets.push({ param: 'hide_installed', label: t('hide-installed'), value: null });
        }
        if (this.compatible) {
            facets.push({ param: 'compatible', label: this.intl.t('registry-bridge.store.filters.compatible'), value: null });
        }

        return facets;
    }

    get tableColumns() {
        const t = (key) => this.intl.t(`registry-bridge.explore.columns.${key}`);
        return [
            { label: t('extension'), valuePath: 'name', cellComponent: 'cell/extension-identity', width: '30%' },
            { label: t('category'), valuePath: 'category_name', width: '15%' },
            { label: t('publisher'), valuePath: 'publisher_name', width: '15%' },
            { label: t('status'), valuePath: 'status', cellComponent: 'cell/extension-status', width: '20%' },
            { label: t('updated'), valuePath: 'updatedAgo', width: '10%' },
            { label: '', valuePath: 'id', cellComponent: 'cell/extension-action', width: '10%' },
        ];
    }

    /** Cards or list, remembered per browser (store-view). */
    get view() {
        return this.storeView.view;
    }

    /**
     * Discover (the storefront: featured, categories, shelves) when nothing is
     * searched, filtered, sorted or paged; otherwise the results of that query.
     */
    get isDiscover() {
        return !this.hasFilters && !this.sort && Number(this.page || 1) === 1;
    }

    @task({ restartable: true }) *load() {
        this.error = null;
        try {
            if (this.isDiscover) {
                yield this.loadDiscover.perform();
            } else {
                this.extensions = yield this.store.query('registry-extension', this.requestParams);
            }
        } catch (error) {
            this.error = error;
            this.extensions = [];
        }
    }

    /**
     * The Discover shelves, in parallel: popular, recently updated, free, and
     * featured. "For <app>" leads when listings say which app they add to.
     */
    @task({ restartable: true }) *loadDiscover() {
        const query = (params) => this.store.query('registry-extension', { explore: 1, limit: SHELF_SIZE, ...params }).then((records) => records.slice());
        const [popular, updated, free, featured] = yield all([
            this.store.query('registry-extension', { explore: 1, limit: PAGE_SIZE }),
            query({ sort: 'updated' }),
            query({ pricing: 'free' }),
            query({ featured: 1, sort: 'featured', limit: 3 }).catch(() => []),
        ]);

        const popularList = popular.slice();
        this.extensions = popular;
        this.featured = discoverFeatured(featured, popularList);
        this.shelves = discoverShelves({ popular: popularList, updated, free }, this.intl);
    }

    @task({ restartable: true }) *search(event) {
        yield timeout(300);
        const query = event?.target?.value ?? '';
        this.query = query.trim() === '' ? null : query;
        this.page = 1;
    }

    @action setView(view) {
        this.storeView.set(view);
    }

    @action toggleCompatible() {
        this.compatible = this.compatible ? null : 1;
        this.page = 1;
    }

    @action setPricing(pricing) {
        this.pricing = ['free', 'paid'].includes(pricing) ? pricing : null;
        this.page = 1;
    }

    @action setLayout(layout) {
        this.layout = layout === 'table' ? 'table' : 'grid';
    }

    @action setSort(sort) {
        this.sort = SORTS.includes(sort) && sort !== 'popular' ? sort : null;
        this.page = 1;
    }

    @action setPage(page) {
        this.page = page;
    }

    @action applyFacets() {
        this.filters.apply(this);
    }

    @action resetFacets() {
        this.filters.reset(this);
        this.page = 1;
    }

    @action clearFacet(param) {
        this[param] = null;
        this.page = 1;
    }

    @action clearAll() {
        this.query = null;
        FACET_PARAMS.forEach((param) => (this[param] = null));
        if (this.queryParams.includes('category')) {
            this.category = null;
        }
        this.page = 1;
    }

    @action retry() {
        return this.load.perform();
    }

    @action openRegistryConfig() {
        return this.hostRouter.transitionTo('console.admin.virtual', 'extension-registry', { queryParams: { view: 'registry-config' } });
    }

    @action openPublisherPortal() {
        if (this.registryContext.publisherUrl) {
            window.open(this.registryContext.publisherUrl, '_blank', 'noopener');
        }
    }
}

/**
 * The featured banner: what staff featured, else the most-installed listing
 * from a verified publisher, else the most-installed one. At most three.
 */
export function discoverFeatured(featured = [], popular = []) {
    const picked = (featured ?? []).filter((extension) => extension?.is_featured === true);
    if (picked.length) {
        return picked.slice(0, 3);
    }
    const fallback = popular.find((extension) => isVerified(extension)) ?? popular[0];
    return fallback ? [fallback] : [];
}

/**
 * The Discover shelves. The first is "For <app>" when at least three popular
 * listings add to the same app (`adds_to`), else "Popular"; then "New and
 * updated" and "Free essentials". Empty shelves are left out. Each carries the
 * query for its See all link.
 */
export function discoverShelves({ popular = [], updated = [], free = [] } = {}, intl) {
    const t = (key, options) => intl.t(`registry-bridge.store.discover.shelves.${key}`, options);
    const byApp = new Map();
    for (const extension of popular) {
        for (const target of Array.isArray(extension?.adds_to) ? extension.adds_to : []) {
            if (target?.name) {
                byApp.set(target.name, [...(byApp.get(target.name) ?? []), extension]);
            }
        }
    }
    const [app, forApp] = [...byApp.entries()].sort((a, b) => b[1].length - a[1].length)[0] ?? [];

    const shelves = [
        forApp && forApp.length >= 3
            ? { id: 'for-app', title: t('for-app', { app }), subtitle: t('for-app-subtitle'), items: forApp.slice(0, SHELF_SIZE), seeAll: null }
            : { id: 'popular', title: t('popular'), subtitle: null, items: popular.slice(0, SHELF_SIZE), seeAll: { sort: 'popular' } },
        { id: 'updated', title: t('updated'), subtitle: null, items: updated.slice(0, SHELF_SIZE), seeAll: { sort: 'updated' } },
        { id: 'free', title: t('free'), subtitle: null, items: free.slice(0, SHELF_SIZE), seeAll: { pricing: 'free' } },
    ];

    return shelves.filter((shelf) => shelf.items.length > 0);
}
