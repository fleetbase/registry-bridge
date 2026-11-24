import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

/**
 * Your extensions › Purchased (design D): the registry organization's paid
 * licences as Registry::LibraryRow rows (plan, installed or not, receipt).
 * Refunded and revoked licences stay listed with their state.
 */
export default class PurchasedController extends Controller {
    @service registryContext;
    @tracked extensions = [];
    @tracked error = null;

    get isLoading() {
        return this.load.isRunning;
    }

    /** Active licences not installed yet; a refunded or revoked one cannot be installed. */
    get readyToInstall() {
        return (this.extensions ?? []).filter((extension) => extension.is_installed !== true && extension.is_purchased === true).length;
    }

    /** The registry organization that holds the licences (not the console company), when known. */
    get organizationName() {
        return this.registryContext.organizationName ?? null;
    }

    @task *load() {
        this.error = null;
        try {
            this.extensions = yield this.model?.load ? this.model.load() : Promise.resolve(this.model ?? []);
            // A refund or revoke on the service changes the Purchased count; keep the tab bar in step with the list
            this.registryContext.loadCounts();
        } catch (error) {
            this.error = error;
            this.extensions = [];
        }
    }
}
