import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/** Installed extensions with an update ready. The full Updates page (what's new, auto-update) comes with the library. */
export default class UpdatesController extends Controller {
    @service registryContext;
    @tracked extensions = [];
    @tracked error = null;

    get rows() {
        return (this.extensions ?? []).filter((extension) => extension.update_available === true);
    }

    get isLoading() {
        return this.load.isRunning;
    }

    @task({ restartable: true }) *load() {
        this.error = null;
        try {
            this.extensions = yield this.model.load();
        } catch (error) {
            this.error = error;
            this.extensions = [];
        }
    }

    @action retry() {
        return this.load.perform();
    }
}
