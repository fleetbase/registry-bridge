import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { PERMISSIONS } from '../utils/extension-state';
import { matchesQuery } from './installed';

/**
 * Purchased extensions (canvas C9) on `Layout::Resource::Tabular`: the
 * registry organization's licences with the price paid, the licence state
 * (active, or refunded or revoked by the service), activations from the
 * instance API, install status, receipt, and Install or About per row.
 */
export default class PurchasedController extends Controller {
    @service extensionPanel;
    @service extensionInstaller;
    @service hostRouter;
    @service intl;
    @service registryContext;

    queryParams = ['query', 'layout'];
    @tracked query = null;
    @tracked layout = 'table';
    @tracked extensions = [];
    @tracked error = null;

    get rows() {
        return (this.extensions ?? []).filter((extension) => matchesQuery(extension, this.query));
    }

    /** Active licences not installed yet; a refunded or revoked one cannot be installed. */
    get readyToInstall() {
        return (this.extensions ?? []).filter((extension) => extension.is_installed !== true && extension.is_purchased === true).length;
    }

    /** Refunded or revoked licences still listed: their extensions can no longer be installed again or updated. */
    get blockedCount() {
        return (this.extensions ?? []).filter((extension) => ['refunded', 'revoked'].includes(extension.meta?.licence_state)).length;
    }

    get organizationName() {
        return this.registryContext.organizationName ?? this.intl.t('registry-bridge.purchase.your-organization');
    }

    get isTable() {
        return this.layout !== 'grid';
    }

    get columns() {
        const t = (key) => this.intl.t(`registry-bridge.purchased.columns.${key}`);
        return [
            { label: t('extension'), valuePath: 'name', cellComponent: 'cell/extension-identity', width: '26%', sortable: false },
            { label: t('price'), valuePath: 'price', cellComponent: 'cell/purchase-price', width: '12%', sortable: false },
            { label: t('licence'), valuePath: 'meta.licence_state', cellComponent: 'cell/licence-state', width: '12%', sortable: false },
            { label: t('activations'), valuePath: 'meta.licence', cellComponent: 'cell/licence-activations', width: '12%', sortable: false },
            { label: t('install-status'), valuePath: 'is_installed', cellComponent: 'cell/extension-status', variant: 'purchased', width: '16%', sortable: false },
            { label: t('receipt'), valuePath: 'meta.licence.receipt_url', cellComponent: 'cell/licence-receipt', width: '14%', sortable: false },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '8%',
                sortable: false,
                actions: [
                    {
                        label: this.intl.t('registry-bridge.common.install'),
                        icon: 'download',
                        fn: this.install,
                        permission: PERMISSIONS.install,
                        isVisible: (extension) => extension.is_installed !== true && extension.is_installable !== false,
                    },
                    { label: this.intl.t('registry-bridge.common.about'), icon: 'circle-info', fn: this.about },
                ],
            },
        ];
    }

    @task *load() {
        this.error = null;
        try {
            this.extensions = yield this.model?.load ? this.model.load() : Promise.resolve(this.model ?? []);
            // A refund or revoke on the service changes the Purchased count; keep the sidebar in step with the list
            this.registryContext.loadCounts();
        } catch (error) {
            this.error = error;
            this.extensions = [];
        }
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

    @action install(extension) {
        return this.extensionInstaller.install(extension, { onCompleted: () => this.load.perform() });
    }

    @action browsePaid() {
        return this.hostRouter.transitionTo('console.extensions.explore.index', { queryParams: { pricing: 'paid' } });
    }
}
