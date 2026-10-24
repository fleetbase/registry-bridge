import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { format as formatDate, isValid } from 'date-fns';
import { PERMISSIONS } from '../../utils/extension-state';

export function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value <= 0) {
        return null;
    }
    if (value < 1024 * 1024) {
        return `${Math.max(1, Math.round(value / 1024))} KB`;
    }
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Versions tab: published and yanked releases from the Registry Service
 * (GET ~registry/v1/registry-extensions/{id}/releases) with Install this
 * version, or the current version only for cloud-catalog records.
 */
export default class ExtensionPanelVersionsComponent extends Component {
    @service fetch;
    @service intl;
    @service abilities;
    @service extensionInstaller;
    @service extensionPanel;
    @tracked releases = [];
    @tracked supported = true;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get extension() {
        return this.args.extension ?? {};
    }

    get canInstall() {
        return this.abilities.can(PERMISSIONS.install);
    }

    get rows() {
        const installable = this.extension.is_installed === true || this.extension.is_installable !== false;
        return this.releases.map((release) => {
            const date = release.published_at ? new Date(release.published_at) : null;
            const blocked = release.is_yanked || release.is_installed || !installable;

            return {
                ...release,
                publishedLabel: date && isValid(date) ? formatDate(date, 'd MMM yyyy') : null,
                sizeLabel: formatBytes(release.size_bytes),
                actionDisabled: blocked || !this.canInstall,
                actionText: this.intl.t(this.extension.is_installed ? 'registry-bridge.detail.versions.switch' : 'registry-bridge.detail.versions.install'),
            };
        });
    }

    get columns() {
        const t = (key) => this.intl.t(`registry-bridge.detail.versions.columns.${key}`);
        return [
            { label: t('version'), valuePath: 'version', cellComponent: 'cell/release-version', width: '22%' },
            { label: t('channel'), valuePath: 'channel', width: '12%' },
            { label: t('date'), valuePath: 'publishedLabel', width: '16%' },
            { label: t('size'), valuePath: 'sizeLabel', width: '12%' },
            { label: t('notes'), valuePath: 'notes', width: '20%' },
            { label: '', valuePath: 'id', cellComponent: 'cell/release-action', onInstall: this.installVersion, width: '18%' },
        ];
    }

    @task *load() {
        const id = this.extension.public_id ?? this.extension.id;
        this.error = null;
        try {
            const response = yield this.fetch.get(`registry-extensions/${id}/releases`, this.extension.installed_version ? { installed_version: this.extension.installed_version } : {}, {
                namespace: '~registry/v1',
            });
            this.supported = response?.supported !== false;
            this.releases = response?.releases ?? [];
        } catch (error) {
            this.error = error;
            this.releases = [];
        }
    }

    @action installVersion(release) {
        const options = { version: release.version, onCompleted: () => this.extensionPanel.reload() };
        return this.extension.is_installed ? this.extensionInstaller.update(this.extension, options) : this.extensionInstaller.install(this.extension, options);
    }
}
