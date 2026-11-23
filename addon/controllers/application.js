import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { tracked } from '@glimmer/tracking';

const CATEGORY_ROUTE = 'console.extensions.explore.category';

export default class ApplicationController extends Controller {
    @service registryContext;
    @service hostRouter;
    @service intl;

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

    /**
     * The sidebar, for `Layout::Sidebar::Navigator` (the drill-down menu Storefront and Ledger use).
     * Explore opens into All and the categories. A category navigates with `onClick` rather than
     * `route`, because the navigator marks every item whose route prefixes the current one as active,
     * and all categories share one route; `activeWhen` matches the slug in the URL instead.
     */
    get navigationItems() {
        const t = (key) => this.intl.t(`registry-bridge.sidebar.${key}`);
        const items = [
            {
                id: 'installed',
                label: t('installed'),
                icon: 'inbox',
                route: 'console.extensions.installed',
                visiblePermission: 'registry-bridge see extension-install',
                permission: 'registry-bridge list extension-install',
                badge: this.installedCount,
                keywords: ['installs', 'updates', 'auto-update', 'uninstall'],
            },
            {
                id: 'purchased',
                label: t('purchased'),
                icon: 'bag-shopping',
                route: 'console.extensions.purchased',
                visiblePermission: 'registry-bridge see extension-purchase',
                permission: 'registry-bridge list extension-purchase',
                badge: this.purchasedCount,
                keywords: ['licences', 'licenses', 'receipts', 'purchases'],
            },
            {
                id: 'explore',
                label: t('explore'),
                icon: 'shapes',
                defaultRoute: 'console.extensions.explore.index',
                visiblePermission: 'registry-bridge see extension',
                permission: 'registry-bridge list extension',
                keywords: ['catalog', 'browse', 'marketplace'],
                children: [
                    {
                        id: 'explore-all',
                        label: t('explore-all'),
                        icon: 'shapes',
                        route: 'console.extensions.explore.index',
                        permission: 'registry-bridge list extension',
                    },
                    ...this.sidebarCategories.map((category) => ({
                        id: `category-${category.slug}`,
                        label: category.name,
                        icon: category.icon || 'folder',
                        permission: 'registry-bridge list extension',
                        badge: category.count && category.count !== '0' ? category.count : null,
                        keywords: [category.slug],
                        onClick: () => this.hostRouter.transitionTo(CATEGORY_ROUTE, category.slug),
                        activeWhen: ({ routeName, router, currentURL }) => isCategoryActive(category.slug, routeName, router, currentURL),
                    })),
                ],
            },
        ];

        const publisherUrl = this.registryContext.publisherUrl;
        if (publisherUrl) {
            items.push({
                id: 'publisher-portal',
                label: t('publisher-portal'),
                icon: 'arrow-up-right-from-square',
                url: publisherUrl,
                target: '_blank',
                keywords: ['publish', 'developers', 'studio'],
            });
        }

        return items;
    }
}

function isCategoryActive(slug, routeName, router, currentURL) {
    if (routeName !== CATEGORY_ROUTE) {
        return false;
    }
    const param = router?.currentRoute?.params?.slug;
    if (param !== undefined) {
        return param === slug;
    }
    return typeof currentURL === 'string' && currentURL.split('?')[0].replace(/\/$/, '').endsWith(`/${slug}`);
}
