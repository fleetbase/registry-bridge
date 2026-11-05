import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { unavailableReason } from '../../utils/extension-state';

/**
 * An extension in a table cell: icon, name and badges (version, update,
 * unavailable here, core) on `Table::Cell::Identity`. Clicking opens the detail
 * panel unless the column has its own `onClick`.
 */
export default class CellExtensionIdentityComponent extends Component {
    @service extensionPanel;
    @service intl;

    get extension() {
        return this.args.row ?? {};
    }

    /**
     * The identity column, with the extension icon fallback from the console
     * config (passed in by the template's `config` helper).
     */
    columnWith = (fallbackImage) => {
        return {
            mediaPath: 'icon_url',
            labelPath: 'name',
            fallbackImage,
            showStatusDot: false,
            popover: false,
            badgeLimit: 3,
            badges: () => this.badges,
            ...(this.args.column ?? {}),
        };
    };

    get badges() {
        const extension = this.extension;
        const version = extension.installed_version || extension.current_bundle_version || extension.version;
        const badges = [];
        if (version) {
            badges.push({ key: 'version', label: `v${version}` });
        }
        if (extension.update_available === true && extension.latest_version) {
            badges.push({
                key: 'update',
                label: this.intl.t('registry-bridge.card.update', { version: extension.latest_version }),
                class: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
            });
        }
        if (unavailableReason(extension)) {
            badges.push({ key: 'unavailable', label: this.intl.t('registry-bridge.card.unavailable'), class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' });
        }
        if (extension.core_extension === true) {
            badges.push({ key: 'core', label: this.intl.t('registry-bridge.installed.status.core') });
        }

        return badges;
    }

    @action open(extension, event) {
        if (typeof this.args.column?.onClick === 'function') {
            return;
        }
        if (typeof this.args.onClick === 'function') {
            return this.args.onClick(extension, event);
        }

        return this.extensionPanel.open(extension ?? this.extension);
    }
}
