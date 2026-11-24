import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export function matchesQuery(extension, query) {
    const needle = (query ?? '').trim().toLowerCase();
    if (!needle) {
        return true;
    }
    return [extension.name, extension.publisher_name, extension.category_name, extension.slug].some((value) => typeof value === 'string' && value.toLowerCase().includes(needle));
}

/**
 * Your extensions › Installed (design D): one Registry::LibraryRow per
 * installed extension, with an updates banner when any have one.
 */
export default class InstalledController extends Controller {
    @tracked extensions = [];
    @tracked error = null;

    get isLoading() {
        return this.load.isRunning;
    }

    /** The first extension with an update, for the banner's one line. */
    get firstUpdate() {
        return (this.extensions ?? []).find((extension) => extension.update_available === true) ?? null;
    }

    get updatesAvailable() {
        return (this.extensions ?? []).filter((extension) => extension.update_available === true).length;
    }

    @task *load() {
        this.error = null;
        try {
            this.extensions = yield this.model?.load ? this.model.load() : Promise.resolve(this.model ?? []);
        } catch (error) {
            this.error = error;
            this.extensions = [];
        }
    }
}
