import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { PERMISSIONS } from '../utils/extension-state';

export function matchesQuery(extension, query) {
    const needle = (query ?? '').trim().toLowerCase();
    if (!needle) {
        return true;
    }
    return [extension.name, extension.publisher_name, extension.category_name, extension.slug].some((value) => typeof value === 'string' && value.toLowerCase().includes(needle));
}

/**
 * Installed extensions (canvas C8) on `Layout::Resource::Tabular` with a grid
 * toggle: installed against latest version, status, and Update, About and
 * Uninstall per row.
 */
export default class InstalledController extends Controller {
    @service extensionPanel;
    @service extensionInstaller;
    @service hostRouter;
    @service abilities;
    @service intl;

    queryParams = ['query', 'layout'];
    @tracked query = null;
    @tracked layout = 'table';
    @tracked extensions = [];
    @tracked error = null;

    get rows() {
        return (this.extensions ?? []).filter((extension) => matchesQuery(extension, this.query));
    }

    get updatesAvailable() {
        return (this.extensions ?? []).filter((extension) => extension.update_available === true).length;
    }

    get isTable() {
        return this.layout !== 'grid';
    }

    get columns() {
        const t = (key) => this.intl.t(`registry-bridge.installed.columns.${key}`);
        return [
            { label: t('extension'), valuePath: 'name', cellComponent: 'cell/extension-identity', width: '30%', sortable: false },
            { label: t('category'), valuePath: 'category_name', width: '15%', sortable: false },
            { label: t('installed'), valuePath: 'installed_version', width: '12%', sortable: false },
            { label: t('latest'), valuePath: 'latest_version', width: '12%', sortable: false },
            { label: t('status'), valuePath: 'update_available', cellComponent: 'cell/extension-status', variant: 'installed', width: '15%', sortable: false },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('registry-bridge.installed.actions'),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '8%',
                sortable: false,
                actions: this.rowActions,
            },
        ];
    }

    get rowActions() {
        return [
            {
                label: this.intl.t('registry-bridge.installed.update'),
                icon: 'arrow-up',
                fn: this.update,
                permission: PERMISSIONS.install,
                isVisible: (extension) => extension.update_available === true,
            },
            { label: this.intl.t('registry-bridge.common.about'), icon: 'circle-info', fn: this.about },
            { separator: true },
            {
                label: this.intl.t('registry-bridge.common.uninstall'),
                icon: 'trash',
                class: 'text-red-500',
                fn: this.uninstall,
                permission: PERMISSIONS.uninstall,
                isVisible: (extension) => extension.core_extension !== true,
            },
        ];
    }

    @task *load() {
        this.error = null;
        try {
            this.extensions = yield this.fetchInstalled();
        } catch (error) {
            this.error = error;
            this.extensions = [];
        }
    }

    fetchInstalled() {
        return this.model?.load ? this.model.load() : Promise.resolve(this.model ?? []);
    }

    @task({ restartable: true }) *search(event) {
        yield timeout(250);
        const query = event?.target?.value ?? '';
        this.query = query.trim() === '' ? null : query;
    }

    @action setLayout(layout) {
        this.layout = layout === 'grid' ? 'grid' : 'table';
    }

    @action about(extension) {
        return this.extensionPanel.open(extension);
    }

    @action update(extension) {
        return this.extensionInstaller.update(extension, { onCompleted: () => this.load.perform() });
    }

    @action uninstall(extension) {
        return this.extensionInstaller.uninstall(extension, { onCompleted: () => this.load.perform() });
    }

    @action explore() {
        return this.hostRouter.transitionTo('console.extensions.explore.index');
    }

    @action purchased() {
        return this.hostRouter.transitionTo('console.extensions.purchased');
    }
}
