import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * The Extensions module's own navigation, on every page: Discover · Installed
 * · Purchased · Updates with their counts, and search. Replaces the module
 * sidebar (the design has none; the application route switches it off).
 * A page can add controls on the right through the block (the view toggle).
 */
export default class RegistryStoreNavComponent extends Component {
    @service hostRouter;
    @service registryContext;
    @service abilities;
    @service storeView;
    @service installJobs;
    @service intl;
    @tracked draft = null;

    /** The cards/list toggle shows where there is a catalog to show: Discover and the category pages. */
    get showView() {
        return (this.hostRouter.currentRouteName ?? '').startsWith('console.extensions.explore');
    }

    get tabs() {
        const count = (key) => this.registryContext.countOf(key);
        const tab = (id, route, permission = null) => ({
            id,
            route,
            // The extension page belongs to Discover
            currentWhen: id === 'discover' ? 'explore extension' : route,
            count: id === 'discover' ? null : count(id),
            visible: permission ? this.abilities.can(permission) : true,
        });

        return [
            tab('discover', 'explore'),
            tab('installed', 'installed', 'registry-bridge list extension-install'),
            tab('purchased', 'purchased'),
            tab('updates', 'updates', 'registry-bridge list extension-install'),
        ].filter((item) => item.visible);
    }

    /** Running installs and updates, as pills that lead back to the extension's page. */
    get jobs() {
        return this.installJobs.running.map((job) => ({
            ...job,
            label: this.intl.t(`registry-bridge.store.pill.${job.action === 'update' ? 'updating' : 'installing'}`, { name: job.name, percent: job.percent ?? 0 }),
        }));
    }

    @action openJob(job) {
        return this.hostRouter.transitionTo('console.extensions.extension', job.id);
    }

    get query() {
        return this.draft ?? this.hostRouter.currentRoute?.queryParams?.query ?? '';
    }

    @action setView(view) {
        this.storeView.set(view);
    }

    @action input(event) {
        this.draft = event.target.value;
    }

    @action submit(event) {
        event.preventDefault();
        const query = (this.draft ?? this.query ?? '').trim();
        this.draft = null;
        return this.hostRouter.transitionTo('console.extensions.explore.index', { queryParams: { query: query === '' ? null : query, page: 1 } });
    }
}
