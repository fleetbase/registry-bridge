import Service, { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { INSTALLER_NAMESPACE, installerChannel, installerStepGroups, runInstallerJob } from '../utils/installer-job';

export const INSTALLER_ACTIONS = ['install', 'update', 'uninstall'];

/**
 * Footer buttons of the installer modal for each phase (canvas C5): confirm,
 * progress, success (reload countdown) and failure (retry).
 *
 * @param {Object} intl
 * @param {String} jobAction install, update or uninstall
 * @param {String} phase
 * @return {Object} modal options
 */
export function installerButtons(intl, jobAction, phase) {
    const t = (key) => intl.t(`registry-bridge.${key}`);
    const uninstall = jobAction === 'uninstall';

    switch (phase) {
        case 'progress':
            return {
                acceptButtonText: t(`${uninstall ? 'uninstall' : 'install'}.progress.button`),
                acceptButtonIcon: 'spinner',
                acceptButtonScheme: uninstall ? 'danger' : 'primary',
                hideDeclineButton: true,
            };
        case 'success':
            return {
                acceptButtonText: t('install.success.reload-now'),
                acceptButtonIcon: 'rotate-right',
                acceptButtonScheme: 'primary',
                declineButtonText: t('install.success.stay'),
                declineButtonIcon: 'pause',
                hideDeclineButton: false,
            };
        case 'failure':
            return {
                acceptButtonText: t(`${uninstall ? 'uninstall' : 'install'}.failed.retry`),
                acceptButtonIcon: 'rotate-right',
                acceptButtonScheme: uninstall ? 'danger' : 'primary',
                declineButtonText: t('common.close'),
                declineButtonIcon: 'times',
                hideDeclineButton: false,
            };
        default:
            return {
                acceptButtonText: t(`${jobAction}.confirm.accept`),
                acceptButtonIcon: uninstall ? 'trash' : jobAction === 'update' ? 'arrow-up' : 'download',
                acceptButtonScheme: uninstall ? 'danger' : 'primary',
                declineButtonText: t('common.cancel'),
                declineButtonIcon: 'times',
                hideDeclineButton: false,
            };
    }
}

/**
 * Install, update and uninstall an extension through the queued installer
 * job, in one modal that confirms, shows the named steps while the job runs,
 * then counts down to the console reload or shows the failed stage with
 * Retry. Nothing closes on its own.
 */
export default class ExtensionInstallerService extends Service {
    @service modalsManager;
    @service notifications;
    @service currentUser;
    @service socket;
    @service fetch;
    @service intl;
    @service abilities;

    /** Seconds before the console reloads after a finished job. */
    reloadAfter = 8;

    /** Replaced in tests. */
    reload = () => window.location.reload();

    @action install(extension, options = {}) {
        return this.open('install', extension, options);
    }

    @action update(extension, options = {}) {
        return this.open('update', extension, options);
    }

    @action uninstall(extension, options = {}) {
        return this.open('uninstall', extension, options);
    }

    /**
     * Show the confirm state for a job on an extension.
     *
     * @param {String} jobAction install, update or uninstall
     * @param {Object} extension
     * @param {Object} [options]
     * @param {String} [options.version] a specific release (install or update)
     * @param {Function} [options.onCompleted] called with the job after success
     */
    open(jobAction, extension, { version = null, onCompleted } = {}) {
        if (!INSTALLER_ACTIONS.includes(jobAction)) {
            throw new Error(`Unknown installer action "${jobAction}".`);
        }

        const permission = jobAction === 'uninstall' ? 'registry-bridge uninstall extension' : 'registry-bridge install extension';
        const targetVersion = version ?? (jobAction === 'uninstall' ? extension.installed_version : extension.latest_version || extension.current_bundle_version) ?? null;

        return this.modalsManager.show('modals/extension-installer', {
            modalClass: 'flb--extension-modal flb--extension-installer-modal modal-md',
            modalHeaderClass: 'flb--extension-modal-header',
            title: this.title(jobAction, extension, targetVersion),
            jobAction,
            extension,
            version: targetVersion,
            installedVersion: extension.installed_version ?? null,
            companyName: this.currentUser.company?.name ?? this.currentUser.user?.company_name ?? null,
            phase: 'confirm',
            steps: installerStepGroups(jobAction, {}),
            confirmName: '',
            progress: 0,
            stepDescription: null,
            installerError: null,
            secondsLeft: null,
            keepOpen: true,
            closeButton: true,
            // Uninstall waits for the typed name (see the modal component)
            acceptButtonDisabled: this.abilities.cannot(permission) || jobAction === 'uninstall',
            ...installerButtons(this.intl, jobAction, 'confirm'),
            confirm: (modal) => this.onConfirm(modal, jobAction, extension, targetVersion, onCompleted),
            decline: (modal) => this.onDecline(modal),
        });
    }

    title(jobAction, extension, version) {
        const name = extension.name;
        if (jobAction === 'uninstall') {
            return this.intl.t('registry-bridge.uninstall.confirm.title', { name });
        }
        if (jobAction === 'update') {
            return this.intl.t('registry-bridge.update.confirm.title', { name, version: version ? `v${version}` : '' });
        }
        return this.intl.t('registry-bridge.install.confirm.title', { name, version: version ? `v${version}` : '' });
    }

    async onConfirm(modal, jobAction, extension, version, onCompleted) {
        const phase = modal.getOption('phase');

        if (phase === 'success') {
            return this.reload();
        }
        if (phase === 'progress') {
            return;
        }
        if (jobAction === 'uninstall' && phase === 'confirm' && (modal.getOption('confirmName') ?? '').trim() !== extension.name) {
            return;
        }

        const proxy = this.modalWithButtons(modal, jobAction);
        try {
            const job = await runInstallerJob({
                modal: proxy,
                intl: this.intl,
                fetch: this.fetch,
                socket: this.socket,
                action: jobAction,
                channel: installerChannel(jobAction, this.currentUser.companyId, extension.id),
                request: () => this.request(jobAction, extension, version),
                reload: () => this.reload(),
                reloadAfter: this.reloadAfter,
            });

            if (job.status === 'completed') {
                const key = jobAction === 'uninstall' ? 'uninstalled' : jobAction === 'update' ? 'updated' : 'installed';
                this.notifications.info(this.intl.t(`registry-bridge.installer.${key}`, { extensionName: extension.name }));
                if (typeof onCompleted === 'function') {
                    onCompleted(job);
                }
            }

            return job;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Cancel, Close and "Wait, stay here". Closing while the job runs is
     * allowed: the job keeps running on the server.
     */
    onDecline(modal) {
        if (modal.getOption('phase') === 'success') {
            modal.invoke('cancelReload');
        }

        return modal.done();
    }

    request(jobAction, extension, version) {
        const payload = { extension: extension.public_id ?? extension.id };
        if (version && jobAction !== 'uninstall') {
            payload.version = version;
        }

        return this.fetch.post(`installer/${jobAction}`, payload, { namespace: INSTALLER_NAMESPACE });
    }

    /**
     * The modals manager, with the phase's footer buttons merged into every
     * options update that changes the phase.
     */
    modalWithButtons(modal, jobAction) {
        const setOptions = (options = {}) => {
            if (!options.phase) {
                return modal.setOptions(options);
            }

            modal.setOptions({ ...options, ...installerButtons(this.intl, jobAction, options.phase) });
            // Reload now and Wait must be pressable while the countdown runs
            if (options.phase === 'success') {
                modal.stopLoading();
            }
        };

        return {
            startLoading: () => modal.startLoading(),
            stopLoading: () => modal.stopLoading(),
            setOption: (key, value) => (key === 'phase' ? setOptions({ phase: value }) : modal.setOption(key, value)),
            setOptions,
        };
    }
}
