import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import isModel from '@fleetbase/ember-core/utils/is-model';

export const PANEL_PARAM = 'extension_id';

function setUrlParam(name, value) {
    const url = new URL(window.location.href);
    if (value) {
        url.searchParams.set(name, value);
    } else {
        url.searchParams.delete(name);
    }
    if (url.href !== window.location.href) {
        window.history.replaceState({ path: url.href }, '', url.href);
    }
}

/**
 * The extension detail panel (canvas C4): one `Layout::Resource::Panel`
 * rendered by the application template over Explore, Installed and
 * Purchased. The open extension is kept in `?extension_id=` so the panel can
 * be linked to and reopens after a checkout redirect.
 */
export default class ExtensionPanelService extends Service {
    @service fetch;
    @service notifications;
    @tracked extension = null;
    @tracked tab = 'overview';
    @tracked isLoading = false;
    @tracked error = null;

    get isOpen() {
        return this.extension !== null || this.isLoading || this.error !== null;
    }

    @action open(extension, { tab = 'overview' } = {}) {
        this.extension = extension;
        this.tab = tab;
        this.error = null;
        setUrlParam(PANEL_PARAM, extension?.public_id ?? extension?.id);
    }

    @action close() {
        this.extension = null;
        this.error = null;
        this.isLoading = false;
        setUrlParam(PANEL_PARAM, null);
    }

    @action selectTab(tab) {
        this.tab = tab;
    }

    /**
     * Open the panel for an id from the URL, loading the record first.
     */
    @action async openById(id, options = {}) {
        if (!id) {
            return null;
        }
        if (this.extension && (this.extension.public_id === id || this.extension.id === id)) {
            return this.extension;
        }

        this.isLoading = true;
        this.error = null;
        try {
            const extension = await this.load(id);
            this.open(extension, options);
            return extension;
        } catch (error) {
            this.error = error;
            return null;
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Reload the open extension (after a purchase or install) and keep the panel on it.
     */
    @action async reload() {
        const id = this.extension?.public_id ?? this.extension?.id;
        if (!id) {
            return null;
        }
        try {
            const extension = await this.load(id);
            this.extension = extension;
            return extension;
        } catch {
            return this.extension;
        }
    }

    async load(id) {
        const extension = await this.fetch.get(`registry-extensions/${id}`, {}, { namespace: '~registry/v1', normalizeToEmberData: true });
        return isModel(extension) ? extension : (extension ?? null);
    }
}
