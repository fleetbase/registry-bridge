import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import InstallerJobTracker, { describeInstallerFailure, installerChannel, jobPercent, jobPhase, technicalStages } from '../utils/installer-job';

/** Remembers a finished job across the reload that mounts the new extension, to say so once the console is back. */
export const FINISHED_KEY = 'registry-bridge:finished-job';

const PERMISSIONS = { install: 'registry-bridge install extension', update: 'registry-bridge install extension', uninstall: 'registry-bridge uninstall extension' };

/**
 * Installs and updates without a modal (design: "the button is the progress
 * bar"). Each running job is tracked per extension, pushed over the socket
 * and polled as a fallback (utils/installer-job), so every Install button for
 * that extension shows its progress, wherever it is.
 *
 * When a job finishes the console reloads to mount the new extension, and
 * says "<name> is ready" once it is back. A failure stays on the extension,
 * with a plain sentence and the technical details for whoever may see them.
 */
export default class InstallJobsService extends Service {
    @service extensionInstaller;
    @service registryContext;
    @service notifications;
    @service currentUser;
    @service abilities;
    @service socket;
    @service fetch;
    @service intl;

    /** id → { action, name, status: running|completed|failed, phase, percent, failure, details } */
    @tracked jobs = {};

    /** Wait after a finished job before the reload, so the full bar is seen. Replaced in tests. */
    reloadDelay = 1200;
    reload = () => window.location.reload();

    entryFor(extension) {
        const id = extension?.id;
        return id ? (this.jobs[id] ?? null) : null;
    }

    /** What the install button draws while a job runs: `{ action, phase, percent }`, else null. */
    progressFor(extension) {
        const entry = this.entryFor(extension);
        return entry && entry.status !== 'failed' ? { action: entry.action, phase: entry.phase, percent: entry.percent } : null;
    }

    /** A running uninstall on an extension (the library row dims and shows "Removing…"), else null. */
    removalFor(extension) {
        const entry = this.entryFor(extension);
        return entry?.action === 'uninstall' && entry.status !== 'failed' ? entry : null;
    }

    /** The last failure on an extension, until it is dismissed or retried. */
    failureFor(extension) {
        const entry = this.entryFor(extension);
        return entry?.status === 'failed' ? entry : null;
    }

    get running() {
        return Object.values(this.jobs).filter((entry) => entry.status === 'running');
    }

    canSeeDetails(action = 'install') {
        return this.currentUser.isAdmin === true || this.abilities.can(PERMISSIONS[action] ?? PERMISSIONS.install);
    }

    set(id, patch) {
        this.jobs = { ...this.jobs, [id]: { ...(this.jobs[id] ?? {}), ...patch } };
    }

    dismiss(extension) {
        const { [extension?.id]: _removed, ...rest } = this.jobs; // eslint-disable-line no-unused-vars
        this.jobs = rest;
    }

    /**
     * Start an install, update or uninstall and follow it.
     *
     * @param {String} action install | update | uninstall
     * @param {Object} extension a catalog record
     * @param {String} [version] a specific release
     * @return {Promise<Object|null>} the finished job, or null when it could not start
     */
    async start(action, extension, version = null) {
        const id = extension?.id;
        if (!id || this.jobs[id]?.status === 'running') {
            return null;
        }
        if (this.registryContext.inMaintenance) {
            this.notifications.warning(this.registryContext.maintenanceMessage ?? this.intl.t('registry-bridge.maintenance.title'));
            return null;
        }
        if (this.abilities.cannot(PERMISSIONS[action] ?? PERMISSIONS.install)) {
            this.notifications.warning(this.intl.t('registry-bridge.store.button.hint.unauthorized'));
            return null;
        }

        const target = action === 'uninstall' ? null : (version ?? extension.latest_version ?? extension.current_bundle_version ?? null);
        this.set(id, { id, action, name: extension.name, status: 'running', phase: 'prepare', percent: 0, failure: null, details: [] });

        const tracker = new InstallerJobTracker({
            fetch: this.fetch,
            socket: this.socket,
            channel: installerChannel(action, this.currentUser.companyId, id),
            onUpdate: (job) => this.set(id, { phase: jobPhase(action, job), percent: jobPercent(action, job) }),
        }).listen();

        let job;
        try {
            const response = await this.extensionInstaller.request(action, extension, target);
            job = await tracker.track(response?.job);
        } catch (error) {
            tracker.stop();
            this.set(id, { status: 'failed', percent: 0, failure: { summary: error?.message ?? this.intl.t('registry-bridge.installer.failed'), message: null, log: [] }, details: [] });
            this.notifications.serverError(error);
            return null;
        }

        if (job.status === 'failed') {
            const failure = describeInstallerFailure(this.intl, job, { action });
            this.set(id, { status: 'failed', percent: 0, failure, details: technicalStages(this.intl, action, job) });
            this.notifications.error(this.intl.t(`registry-bridge.store.done.${action === 'uninstall' ? 'remove-failed' : 'failed'}`, { name: extension.name }));
            return job;
        }

        this.set(id, { status: 'completed', phase: 'finish', percent: 99 });
        this.remember({ action, name: extension.name, id, paid: extension.payment_required === true });
        // The job ends by reloading the API's workers; reloading the console before the API answers again would
        // fail its session check and sign the user out. Wait for it, still on "Finishing up…".
        await this.waitForApi();
        this.set(id, { percent: 100 });
        setTimeout(() => this.reload(), this.reloadDelay);

        return job;
    }

    /** How long to wait for the API after a job, and how often to ask. Replaced in tests. */
    apiWaitTimeout = 120000;
    apiWaitInterval = 3000;
    wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    /**
     * Resolve once the API answers again (the public runtime engine list), or after `apiWaitTimeout`.
     */
    async waitForApi() {
        const started = Date.now();
        await this.wait(this.apiWaitInterval);
        while (Date.now() - started < this.apiWaitTimeout) {
            try {
                await this.fetch.get('engines/runtime', {}, { namespace: '~registry/v1' });
                return true;
            } catch {
                await this.wait(this.apiWaitInterval);
            }
        }
        return false;
    }

    remember(note) {
        try {
            window.sessionStorage.setItem(FINISHED_KEY, JSON.stringify(note));
        } catch {
            // Only the "is ready" notice after the reload is lost.
        }
    }

    /** After the reload: "<name> is ready" (or updated), once. */
    announce() {
        let note = null;
        try {
            note = JSON.parse(window.sessionStorage.getItem(FINISHED_KEY) ?? 'null');
            window.sessionStorage.removeItem(FINISHED_KEY);
        } catch {
            return;
        }
        if (note?.name) {
            const key = note.action === 'update' ? 'updated' : note.action === 'uninstall' ? (note.paid ? 'removed-paid' : 'removed') : 'installed';
            this.notifications.success(this.intl.t(`registry-bridge.store.done.${key}`, { name: note.name }));
        }
    }
}
