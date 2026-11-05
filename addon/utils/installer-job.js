/**
 * Follows an installer job (install, uninstall or update) from the moment it
 * is queued until it completes or fails.
 *
 * The API queues the job and returns `{ status: 'queued', job }`. Progress
 * then arrives two ways, and the tracker merges both:
 *
 *   socket  `install.{company}.{extension}` (`uninstall.…` for uninstalls):
 *           `{ job, step, stage, progress, status }`, plus `message` and `log`
 *           on failure
 *   polling GET ~registry/v1/installer/jobs/{id}: the job record, which also
 *           carries the queue position, and catches anything the socket missed
 *           (the socket service subscribes ~300 ms after `listen`, and
 *           reconnects drop events)
 *
 * Everything it needs (fetch, socket, timers) is passed in, so it can be
 * tested without the app.
 */

export const INSTALLER_NAMESPACE = '~registry/v1';
export const FINISHED_STATUSES = ['completed', 'failed'];

/**
 * Socket steps the installer publishes, in pipeline order, with their
 * translation keys under `registry-bridge.installer.steps`.
 */
export const INSTALLER_STEPS = {
    'release.resolve': 'resolve',
    'release.download': 'download',
    'snapshot.create': 'snapshot',
    'server.install': 'server-install',
    'server.uninstall': 'server-uninstall',
    'engine.install': 'engine-install',
    'engine.uninstall': 'engine-uninstall',
    'console.build': 'console-build',
    'cache.clear': 'cache-clear',
    'install.record': 'record',
    rollback: 'rollback',
};

/**
 * The three named steps the install modal shows (canvas C5), each covering
 * the installer steps that belong to it. Uninstalls remove instead of
 * install; updates install.
 */
export const INSTALLER_STEP_GROUPS = {
    install: [
        { key: 'server', steps: ['release.resolve', 'release.download', 'snapshot.create', 'server.install'] },
        { key: 'engine', steps: ['engine.install'] },
        { key: 'build', steps: ['console.build', 'cache.clear', 'install.record'] },
    ],
    uninstall: [
        { key: 'server', steps: ['snapshot.create', 'server.uninstall'] },
        { key: 'engine', steps: ['engine.uninstall'] },
        { key: 'build', steps: ['console.build', 'cache.clear', 'install.record'] },
    ],
};

/**
 * The named steps for a job with their status: `done`, `active`, `pending`
 * or `failed`.
 *
 * @param {String} action install, update or uninstall
 * @param {Object} job
 * @return {Array<{ key: String, status: String }>}
 */
export function installerStepGroups(action, job = {}) {
    const groups = INSTALLER_STEP_GROUPS[action === 'uninstall' ? 'uninstall' : 'install'];
    const status = job?.status;
    const step = status === 'failed' && (job.step === 'rollback' || !job.step) ? (job.result?.step ?? job.step) : job?.step;
    const index = groups.findIndex((group) => group.steps.includes(step));

    return groups.map((group, i) => {
        if (status === 'completed') {
            return { key: group.key, status: 'done' };
        }
        if (status === 'queued' || status === undefined || status === null) {
            return { key: group.key, status: 'pending' };
        }

        const current = index === -1 ? 0 : index;
        if (i < current) {
            return { key: group.key, status: 'done' };
        }
        if (i === current) {
            return { key: group.key, status: status === 'failed' ? 'failed' : 'active' };
        }
        return { key: group.key, status: 'pending' };
    });
}

/**
 * The socket channel for a job: uninstalls publish on `uninstall.…`,
 * installs and updates on `install.…`.
 *
 * @param {String} action install, uninstall or update
 * @param {String} companyId
 * @param {String} extensionId the extension uuid
 * @return {String}
 */
export function installerChannel(action, companyId, extensionId) {
    return `${action === 'uninstall' ? 'uninstall' : 'install'}.${companyId}.${extensionId}`;
}

export function isFinished(job) {
    return FINISHED_STATUSES.includes(job?.status);
}

/**
 * The progress bar state for a job: a title and a percentage. Per-step
 * progress (0-100) is what the installer publishes; a queued or just-started
 * job shows a sliver so the bar is visible.
 *
 * @param {Object} intl the intl service
 * @param {Object} job
 * @return {{ stepDescription: String, progress: Number }}
 */
export function describeInstallerJob(intl, job = {}) {
    const t = (key, options) => intl.t(`registry-bridge.installer.${key}`, options);

    if (job.status === 'queued') {
        return { stepDescription: job.position > 0 ? t('queued-behind', { count: job.position }) : t('queued-next'), progress: 5 };
    }
    if (job.status === 'completed') {
        return { stepDescription: t('completed'), progress: 100 };
    }
    if (job.status === 'failed') {
        return { stepDescription: t('failed'), progress: 100 };
    }

    const key = INSTALLER_STEPS[job.step];
    return { stepDescription: key ? t(`steps.${key}`) : t('starting'), progress: Math.max(5, Number(job.progress) || 0) };
}

/**
 * What the modal shows for a failed job: the step it failed in, the message
 * and the log tail.
 *
 * @param {Object} intl
 * @param {Object} job
 * @return {{ step: String, message: String, log: Array<String>, rolledBack: Boolean, rebuildRequired: Boolean }}
 */
export function describeInstallerFailure(intl, job = {}) {
    const key = INSTALLER_STEPS[job.step] ?? INSTALLER_STEPS[job.result?.step];

    return {
        step: key ? intl.t(`registry-bridge.installer.steps.${key}`) : (job.failed_stage ?? job.stage ?? null),
        message: job.message ?? job.result?.message ?? intl.t('registry-bridge.installer.failed'),
        log: job.log ?? job.result?.log ?? [],
        rolledBack: job.result?.rollback?.performed === true,
        rebuildRequired: job.result?.rollback?.console_rebuild_required === true,
    };
}

export default class InstallerJobTracker {
    job = null;
    stopped = false;
    pollErrors = 0;

    /**
     * @param {Object} options
     * @param {Object} options.fetch the fetch service (`get(path, query, options)`)
     * @param {Object} options.socket the socket service (`listen(channel, callback)`)
     * @param {String} options.channel
     * @param {Function} [options.onUpdate] called with the merged job on every change
     * @param {Number} [options.pollInterval] milliseconds between job polls
     * @param {Function} [options.schedule] `(fn, ms) => handle`, defaults to setTimeout
     * @param {Function} [options.cancel] `(handle) => void`, defaults to clearTimeout
     */
    constructor({ fetch, socket, channel, onUpdate, pollInterval = 3000, schedule, cancel }) {
        this.fetch = fetch;
        this.socket = socket;
        this.channel = channel;
        this.onUpdate = onUpdate ?? (() => {});
        this.pollInterval = pollInterval;
        this.schedule = schedule ?? ((fn, ms) => setTimeout(fn, ms));
        this.cancel = cancel ?? ((handle) => clearTimeout(handle));
        this.buffered = [];
        this.timer = null;
        this.promise = new Promise((resolve) => (this.resolve = resolve));
    }

    /**
     * Subscribe to the job's channel. Call before the request that queues the
     * job, so early events are not lost; they are held until track().
     */
    listen() {
        this.socket.listen(this.channel, (event) => this.handleEvent(event));
        return this;
    }

    /**
     * Follow a job returned by the API. Resolves with the final job record.
     * A response without a job (preinstalled instances) or with a finished one
     * (synchronous installs) resolves straight away.
     *
     * @param {Object|null} job
     * @return {Promise<Object>}
     */
    track(job) {
        if (!job || !job.id) {
            return this.finish({ status: 'completed', ...(job ?? {}) });
        }

        this.update(job);
        if (isFinished(job)) {
            return this.finish(this.job);
        }

        const buffered = this.buffered;
        this.buffered = [];
        buffered.forEach((event) => this.handleEvent(event));
        if (!this.stopped) {
            this.schedulePoll();
        }

        return this.promise;
    }

    handleEvent(event = {}) {
        if (this.stopped) {
            return;
        }
        if (!this.job) {
            this.buffered.push(event);
            return;
        }
        // Another job on the same channel (an earlier attempt, another tab).
        if (event.job && event.job !== this.job.id) {
            return;
        }

        if (FINISHED_STATUSES.includes(event.status)) {
            const final = { ...this.job, status: event.status, progress: 100 };
            if (event.status === 'failed') {
                Object.assign(final, { step: event.step ?? this.job.step, failed_stage: event.stage, message: event.message, log: event.log ?? [] });
            }
            this.update(final);
            // The record has the full result; the event is enough if it cannot be read.
            this.poll({ final: true });
            return;
        }

        const patch = { status: 'running', position: 0 };
        for (const key of ['step', 'stage', 'progress']) {
            if (event[key] !== undefined) {
                patch[key] = event[key];
            }
        }
        this.update(patch);
    }

    /**
     * Read the job record once. Resolves when this poll is done (not when the
     * job is); the job's outcome is the promise track() returned.
     */
    async poll({ final = false } = {}) {
        if (this.stopped || !this.job) {
            return;
        }

        try {
            const { job } = await this.fetch.get(`installer/jobs/${this.job.id}`, {}, { namespace: INSTALLER_NAMESPACE });
            this.pollErrors = 0;
            if (this.stopped) {
                return;
            }
            // A final socket event wins over a record that has not caught up yet.
            if (job && (isFinished(job) || !isFinished(this.job))) {
                this.update(job);
            }
        } catch {
            this.pollErrors++;
        }

        if (final || isFinished(this.job)) {
            this.finish(this.job);
            return;
        }

        this.schedulePoll();
    }

    schedulePoll() {
        this.cancel(this.timer);
        // Back off while the API is unreachable (a restarting container), up to 30 s.
        const delay = Math.min(this.pollInterval * 2 ** Math.min(this.pollErrors, 4), 30000);
        this.timer = this.schedule(() => this.poll(), delay);
    }

    update(patch) {
        this.job = { ...(this.job ?? {}), ...patch };
        this.onUpdate(this.job);
    }

    finish(job) {
        this.stop();
        this.job = job;
        this.resolve(job);
        return this.promise;
    }

    stop() {
        this.stopped = true;
        this.cancel(this.timer);
        this.timer = null;
    }
}

/**
 * Count down before reloading the console, so a finished install shows its
 * success state first. Returns a function that cancels the countdown.
 *
 * @param {Number} seconds
 * @param {Function} onTick called with the seconds left, from `seconds` to 1
 * @param {Function} onDone
 * @param {Function} [schedule] `(fn, ms) => handle`
 * @param {Function} [cancel]
 * @return {Function}
 */
export function countdown(seconds, onTick, onDone, schedule = (fn, ms) => setTimeout(fn, ms), cancel = (handle) => clearTimeout(handle)) {
    let left = seconds;
    let handle = null;
    const tick = () => {
        if (left <= 0) {
            return onDone();
        }
        onTick(left);
        left--;
        handle = schedule(tick, 1000);
    };
    tick();

    return () => cancel(handle);
}

/**
 * Run an installer request inside an open modal (extension details or
 * uninstall) and drive its state: progress while queued and running, a
 * reload countdown on success, and on failure the step, message and log tail
 * with the accept button turned into Retry. The modal must be shown with
 * `keepOpen: true` so a failure stays on screen.
 *
 * Resolves with the final job. Rejects when the request itself is refused
 * (already installed, validation); the caller shows that error.
 *
 * @param {Object} options
 * @param {Object} options.modal the modals manager passed to `confirm`
 * @param {Object} options.intl
 * @param {Object} options.fetch
 * @param {Object} options.socket
 * @param {String} options.channel
 * @param {Function} options.request sends the install/uninstall request, resolves with the API response
 * @param {Function} [options.reload] reloads the console after the countdown
 * @param {Number} [options.reloadAfter] seconds
 * @param {Function} [options.schedule]
 * @param {Function} [options.cancel]
 * @return {Promise<Object>}
 */
export async function runInstallerJob({
    modal,
    intl,
    fetch,
    socket,
    channel,
    request,
    action = 'install',
    reload = () => window.location.reload(),
    reloadAfter = 3,
    schedule,
    cancel,
    pollInterval,
}) {
    const progress = (job) => ({ ...describeInstallerJob(intl, job), steps: installerStepGroups(action, job), phase: 'progress', job });

    modal.startLoading();
    modal.setOptions({ installerError: null, ...progress({ status: 'running' }) });

    const tracker = new InstallerJobTracker({ fetch, socket, channel, schedule, cancel, pollInterval, onUpdate: (job) => modal.setOptions(progress(job)) }).listen();

    let job;
    try {
        const response = await request();
        job = await tracker.track(response?.job);
    } catch (error) {
        tracker.stop();
        modal.stopLoading();
        modal.setOptions({ progress: 0, phase: 'confirm' });
        throw error;
    }

    if (job.status === 'failed') {
        modal.stopLoading();
        modal.setOptions({
            progress: 0,
            phase: 'failure',
            steps: installerStepGroups(action, job),
            installerError: describeInstallerFailure(intl, job),
            acceptButtonText: intl.t('registry-bridge.installer.retry'),
            acceptButtonIcon: 'rotate-right',
        });
        return job;
    }

    modal.setOptions({ ...describeInstallerJob(intl, job), phase: 'success', steps: installerStepGroups(action, job), job, secondsLeft: reloadAfter });
    const stopCountdown = countdown(
        reloadAfter,
        (seconds) => modal.setOptions({ stepDescription: intl.t('registry-bridge.installer.reloading', { seconds }), secondsLeft: seconds }),
        reload,
        schedule,
        cancel
    );
    // "Wait, stay here" on the success state
    modal.setOptions({
        cancelReload: () => {
            stopCountdown();
            modal.stopLoading();
            modal.setOptions({ secondsLeft: null, reloadCancelled: true });
        },
    });

    return job;
}
