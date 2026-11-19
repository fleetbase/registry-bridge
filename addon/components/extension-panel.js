import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export const PANEL_TABS = ['overview', 'screenshots', 'details', 'versions'];

/**
 * The extension detail panel (canvas C4) on `Layout::Resource::Panel`:
 * header with the call to action, then Overview, Screenshots, Details and
 * Versions. Opened through the `extension-panel` service.
 */
export default class ExtensionPanelComponent extends Component {
    @service extensionPanel;
    @service intl;
    @tracked overlay;

    get extension() {
        return this.extensionPanel.extension;
    }

    get tabs() {
        const screenshots = this.extension?.screenshots?.length ?? 0;
        return PANEL_TABS.map((id) => ({
            id,
            label: this.intl.t(`registry-bridge.detail.tabs.${id}`),
            badgeText: id === 'screenshots' && screenshots > 0 ? String(screenshots) : undefined,
        }));
    }

    @action selectTab(tab) {
        this.extensionPanel.selectTab(tab?.id ?? tab);
    }

    @action close() {
        this.extensionPanel.close();
    }
}
