import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { next } from '@ember/runloop';
import { PANEL_PARAM } from '../services/extension-panel';

export default class ApplicationRoute extends Route {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service registryContext;
    @service extensionPanel;
    @service extensionPurchase;
    @service urlSearchParams;
    @service sidebar;
    @service installJobs;

    beforeModel() {
        if (this.abilities.cannot('registry-bridge see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    /**
     * The module has its own tab bar (Registry::StoreNav), so the console sidebar is off while it is open. Checked
     * after every transition that ends here: a route left on the way in (the dashboard) may re-enable it as it exits.
     */
    activate() {
        super.activate(...arguments);
        this.sidebar.disable?.();
        // The module's styles hide the console sidebar while this class is on the body (see .rb-store-open); the
        // sidebar service alone leaves its space behind on a fresh load.
        document.body.classList.add('rb-store-open');
        this.hostRouter.on('routeDidChange', this, this.keepSidebarOff);
        this.keepSidebarOff();
    }

    deactivate() {
        super.deactivate(...arguments);
        this.hostRouter.off('routeDidChange', this, this.keepSidebarOff);
        document.body.classList.remove('rb-store-open');
        this.sidebar.enable?.();
    }

    keepSidebarOff() {
        if ((this.hostRouter.currentRouteName ?? '').startsWith('console.extensions')) {
            next(() => {
                this.sidebar.disable?.();
                // Hidden before its first layout (a fresh load), the sidebar keeps its space: its negative margin is
                // sized from a width measured as 0. Hiding again once rendered measures it.
                this.sidebar.context?.hide?.(true);
            });
        }
    }

    model() {
        return this.fetch.get('categories', {}, { namespace: '~registry/v1', normalizeToEmberData: true, modelType: 'category' }).catch(() => []);
    }

    setupController() {
        super.setupController(...arguments);
        this.registryContext.load();
        this.registryContext.loadCounts();
        this.registryContext.watchCounts(this.hostRouter);
        this.restoreFromUrl();
        this.installJobs.announce();
    }

    /**
     * Reopen the panel from `?extension_id=`, and finish a purchase when the
     * page is the return from a checkout.
     */
    async restoreFromUrl() {
        const resumed = await this.extensionPurchase.resume();
        if (resumed === null) {
            const extensionId = this.urlSearchParams.get(PANEL_PARAM);
            if (extensionId) {
                await this.extensionPanel.openById(extensionId);
            }
        }
    }
}
