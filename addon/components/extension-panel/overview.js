import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

/**
 * Overview tab: description, promotional text, compatibility, install count,
 * last release, support and tags.
 */
export default class ExtensionPanelOverviewComponent extends Component {
    @service intl;

    get extension() {
        return this.args.extension ?? {};
    }

    get compat() {
        const compat = this.extension.meta?.compat ?? {};
        const consoleRange = compat.console ?? compat.fleetbase ?? compat['@fleetbase/console'] ?? null;
        const apiRange = compat.api ?? compat['core-api'] ?? compat['fleetbase/core-api'] ?? null;
        if (!consoleRange && !apiRange) {
            return null;
        }

        return this.intl.t('registry-bridge.detail.compat', { console: consoleRange ?? '*', api: apiRange ?? '*' });
    }

    get lastRelease() {
        const release = this.extension.meta?.latest_release;
        return release?.version ? release : null;
    }
}
