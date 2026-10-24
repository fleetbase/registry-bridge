import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { installerChannel, runInstallerJob } from '../utils/installer-job';

export default class InstalledController extends Controller {
    @service modalsManager;
    @service currentUser;
    @service notifications;
    @service socket;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service fetch;

    @action about(extension) {
        this.modalsManager.show('modals/extension-details', {
            titleComponent: 'extension-modal-title',
            modalClass: 'flb--extension-modal modal-lg',
            modalHeaderClass: 'flb--extension-modal-header',
            acceptButtonText: 'Done',
            hideDeclineButton: true,
            extension,
        });
    }

    @action uninstall(extension) {
        const uninstallChannel = installerChannel('uninstall', this.currentUser.companyId, extension.id);

        this.modalsManager.show('modals/extension-uninstall', {
            title: `Uninstall ${extension.name}`,
            modalClass: 'flb--extension-modal modal-lg',
            modalHeaderClass: 'flb--extension-modal-header',
            acceptButtonText: 'Uninstall',
            acceptButtonIcon: 'trash',
            acceptButtonScheme: 'danger',
            acceptButtonDisabled: this.abilities.cannot('registry-bridge uninstall extension'),
            process: null,
            step: null,
            stepDescription: 'Awaiting uninstall to begin...',
            progress: 0,
            extension,
            // Stay open after a failed uninstall so the error and Retry are shown.
            keepOpen: true,
            confirm: async (modal) => {
                // Queue the uninstall and follow the job until it completes or fails
                try {
                    const job = await runInstallerJob({
                        modal,
                        intl: this.intl,
                        fetch: this.fetch,
                        socket: this.socket,
                        channel: uninstallChannel,
                        request: () => extension.uninstall(),
                    });
                    if (job.status === 'completed') {
                        this.notifications.info(this.intl.t('registry-bridge.installer.uninstalled', { extensionName: extension.name }));
                        await this.hostRouter.refresh();
                    }
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }
}
