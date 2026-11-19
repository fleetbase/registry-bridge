import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

export const FACET_PARAMS = ['pricing', 'hosting', 'hide_installed'];
export const SORTS = ['popular', 'newest', 'updated', 'name'];
export const PAGE_SIZE = 24;

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

    queryParams = ['query', 'category', 'pricing', 'hosting', 'hide_installed', 'sort', 'page', 'layout'];
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

    @task({ restartable: true }) *load() {
        this.error = null;
        try {
            this.extensions = yield this.store.query('registry-extension', this.requestParams);
        } catch (error) {
            this.error = error;
            this.extensions = [];
        }
    }

    @task({ restartable: true }) *search(event) {
        yield timeout(300);
        const query = event?.target?.value ?? '';
        this.query = query.trim() === '' ? null : query;
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
