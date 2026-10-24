import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Empty state for the Explore pages: nothing is published to the configured registry yet.
 * Admins get a shortcut to the Extensions Registry admin panel to point the console at a registry.
 */
export default class ExploreEmptyStateComponent extends Component {
    @service hostRouter;
    @service currentUser;

    get canConfigureRegistry() {
        return this.currentUser.isAdmin === true;
    }

    @action goToRegistryConfig() {
        return this.hostRouter.transitionTo('console.admin.virtual', 'extension-registry', { queryParams: { view: 'registry-config' } });
    }
}
