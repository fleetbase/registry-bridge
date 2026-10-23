import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
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

    beforeModel() {
        if (this.abilities.cannot('registry-bridge see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    model() {
        return this.fetch.get('categories', {}, { namespace: '~registry/v1', normalizeToEmberData: true, modelType: 'category' }).catch(() => []);
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.rememberCategoryCounts(model);
        this.registryContext.load();
        this.registryContext.loadCounts();
        this.restoreFromUrl();
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
