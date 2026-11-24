import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import isModel from '@fleetbase/ember-core/utils/is-model';

/** The old detail panel's URL parameter; links that still carry it open the extension page instead. */
export const PANEL_PARAM = 'extension_id';

const PAGE_ROUTE = 'console.extensions.extension';

/**
 * Loads one extension and opens its page (the `extension` route). It replaced
 * the slide-over detail panel; the name stays so the purchase flow and old
 * links keep working.
 */
export default class ExtensionPanelService extends Service {
    @service fetch;
    @service hostRouter;
    /** The extension last opened or loaded, for reload() after a purchase. */
    @tracked extension = null;

    /** Open an extension's page. */
    @action open(extension) {
        const id = extension?.public_id ?? extension?.id ?? extension?.slug;
        if (!id) {
            return null;
        }
        this.extension = extension;
        return this.hostRouter.transitionTo(PAGE_ROUTE, id);
    }

    /** Load an extension by public id or slug, then open its page. */
    @action async openById(id) {
        if (!id) {
            return null;
        }
        try {
            const extension = await this.load(id);
            if (extension) {
                this.open(extension);
            }
            return extension;
        } catch {
            return null;
        }
    }

    /** Reload the last extension (after a purchase completes), keeping the old record if that fails. */
    @action async reload() {
        const id = this.extension?.public_id ?? this.extension?.id;
        if (!id) {
            return null;
        }
        try {
            this.extension = await this.load(id);
        } catch {
            // keep the record we have
        }
        return this.extension;
    }

    async load(id) {
        const extension = await this.fetch.get(`registry-extensions/${id}`, {}, { namespace: '~registry/v1', normalizeToEmberData: true });
        return isModel(extension) ? extension : (extension ?? null);
    }
}
