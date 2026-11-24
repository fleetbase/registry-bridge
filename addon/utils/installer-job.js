/**
 * Follows an installer job (install, uninstall or update) from the moment it
 * is queued until it completes or fails, and turns it into what the modal
 * shows: a few plain steps, one progress bar and a friendly status line, with
 * the technical stages kept for "Show details".
 *
 * Progress is pushed over the socket and polling is only the safety net:
 *
 *   socket  `install.{company}.{extension}` (`uninstall.…` for uninstalls),
 *           authorised for the company's users only (core-api
 *           CoreChannelResolvers::install). Events (`v: 2`):
 *           `{ job, action, status, phase, percent, seq, stage, step,
 *           progress }`, plus `message` and `log` on failure. `seq` grows by
 *           one per event, so a gap means one was missed.
 *   polling GET ~registry/v1/installer/jobs/{id}: the job record (same fields
 *           plus queue `position`, `timings`, `result`). Every 15 s while the
 *           socket is subscribed, every 4 s when it is not, at once after a
 *           missed event, and always once on completion: the final state is
 *           read from the API, which stores it before the final event goes out.
 *
 * Everything it needs (fetch, socket, timers) is passed in, so it can be
 * tested without the app.
 */

export const INSTALLER_NAMESPACE = '~registry/v1';
export const FINISHED_STATUSES = ['completed', 'failed'];

/**
 * The plain steps the modal shows, per action (InstallProgress::PHASES on the
 * server). Labels: `registry-bridge.installer.phases.{key}`.
 */
export const INSTALLER_PHASES = {
    install: ['prepare', 'download', 'install', 'finish'],
    update: ['prepare', 'download', 'install', 'finish'],
    uninstall: ['prepare', 'remove', 'finish'],
};

/**
 * Technical stages in pipeline order with their labels under
 * `registry-bridge.installer.steps`, for the details view.
 */
export const INSTALLER_STAGES = ['resolve', 'download', 'snapshot', 'composer', 'engine', 'build', 'caches', 'record'];

export const STAGE_LABELS = {
    resolve: 'resolve',
    download: 'download',
    snapshot: 'snapshot',
    composer: 'server-install',
    engine: 'engine-install',
    build: 'console-build',
    caches: 'cache-clear',
    record: 'record',
};

/**
 * Socket steps the installer publishes, with their translation keys under
 * `registry-bridge.installer.steps`.
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

const STEP_STAGES = {
    'release.resolve': 'resolve',
    'release.download': 'download',
    'snapshot.create': 'snapshot',
    'server.install': 'composer',
    'server.uninstall': 'composer',
    'engine.install': 'engine',
    'engine.uninstall': 'engine',
    'console.build': 'build',
    'cache.clear': 'caches',
    'install.record': 'record',
};

export function phasesFor(action) {
    return INSTALLER_PHASES[action] ?? INSTALLER_PHASES.install;
}

/**
 * The phase a stage belongs to, for records and events from before `phase`
 * was sent (mirrors InstallProgress::phase).
 */
export function phaseOfStage(action, stage) {
    const uninstall = action === 'uninstall';
    switch (stage) {
        case undefined:
        case null:
        case 'resolve':
            return 'prepare';
        case 'download':
            return uninstall ? 'prepare' : 'download';
        case 'snapshot':
            return uninstall ? 'prepare' : 'install';
        case 'caches':
        case 'record':
            return 'finish';
        default:
            return uninstall ? 'remove' : 'install';
    }
}

/**
 * The phase a job is in now, or failed in.
 */
export function jobPhase(action, job = {}) {
    if (job?.status === 'completed') {
        return 'finish';
    }
    if (job?.status === 'failed') {
        return job.result?.failed_phase ?? job.failed_phase ?? phaseOfStage(action, job.failed_stage ?? job.stage ?? STEP_STAGES[job.result?.step ?? job.step]);
    }
    return job?.phase ?? phaseOfStage(action, job?.stage ?? STEP_STAGES[job?.step]);
}

/**
 * The plain steps for a job with their status: `done`, `active`, `pending`
 * or `failed`.
 *
 * @param {String} action install, update or uninstall
 * @param {Object} job
 * @return {Array<{ key: String, status: String }>}
 */
export function installerPhases(action, job = {}) {
    const phases = phasesFor(action);
    const status = job?.status;

    if (status === 'completed') {
        return phases.map((key) => ({ key, status: 'done' }));
    }
    if (status === 'queued' || status === undefined || status === null) {
        return phases.map((key) => ({ key, status: 'pending' }));
    }

    const current = Math.max(0, phases.indexOf(jobPhase(action, job)));
    return phases.map((key, i) => {
        if (i < current) {
            return { key, status: 'done' };
        }
        if (i === current) {
            return { key, status: status === 'failed' ? 'failed' : 'active' };
        }
        return { key, status: 'pending' };
    });
}

/** @deprecated the modal shows phases now; kept for callers of the old name */
export const installerStepGroups = installerPhases;

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
 * The overall percentage for the one progress bar: the server's `percent`,
 * or for older records an estimate from the phase and the step's progress.
 */
export function jobPercent(action, job = {}) {
    if (job.status === 'completed') {
        return 100;
    }
    if (typeof job.percent === 'number' && job.percent > 0) {
        return Math.min(99, job.percent);
    }
    const phases = phasesFor(action);
    const index = Math.max(0, phases.indexOf(jobPhase(action, job)));
    const step = Math.max(0, Math.min(100, Number(job.progress) || 0));

    return Math.min(99, Math.floor(((index + step / 100) / phases.length) * 100));
}

/**
 * The progress bar state for a job: a friendly status line and the overall
 * percentage. A queued or just-started job shows a sliver so the bar is
 * visible.
 *
 * @param {Object} intl the intl service
 * @param {Object} job
 * @param {Object} [options]
 * @param {String} [options.action] install, update or uninstall
 * @param {String} [options.name] the extension name
 * @return {{ stepDescription: String, progress: Number }}
 */
export function describeInstallerJob(intl, job = {}, { action = 'install', name = '' } = {}) {
    const t = (key, options) => intl.t(`registry-bridge.installer.${key}`, options);

    if (job.status === 'queued') {
        return { stepDescription: job.position > 0 ? t('queued-behind', { count: job.position }) : t('queued-next'), progress: 3 };
    }
    if (job.status === 'completed') {
        return { stepDescription: t('completed'), progress: 100 };
    }
    if (job.status === 'failed') {
        return { stepDescription: t('failed'), progress: 100 };
    }

    const progress = Math.max(3, jobPercent(action, job));
    if (job.step === 'rollback') {
        return { stepDescription: t('status.rollback'), progress };
    }
    if (job.stage === 'build' || job.step === 'console.build') {
        return { stepDescription: t('status.build'), progress };
    }
    if (!job.step && !job.phase) {
        return { stepDescription: t('starting'), progress };
    }

    const phase = jobPhase(action, job);
    const key = phase === 'install' && action === 'update' ? 'update' : phase;
    return { stepDescription: t(`status.${key}`, { name }), progress };
}

/**
 * The technical stages for the details view: each stage that ran or runs now,
 * with its status and how long it took (seconds).
 *
 * @param {Object} intl
 * @param {String} action
 * @param {Object} job
 * @return {Array<{ key: String, label: String, status: String, seconds: Number|null }>}
 */
export function technicalStages(intl, action, job = {}) {
    const timings = job.timings ?? job.result?.timings ?? {};
    const failed = job.status === 'failed' ? (job.failed_stage ?? job.result?.failed_stage ?? null) : null;
    const current = job.status === 'running' ? (job.stage ?? STEP_STAGES[job.step] ?? null) : null;
    const label = (stage) => {
        const key = action === 'uninstall' && stage === 'composer' ? 'server-uninstall' : action === 'uninstall' && stage === 'engine' ? 'engine-uninstall' : STAGE_LABELS[stage];
        return key ? intl.t(`registry-bridge.installer.steps.${key}`) : stage;
    };

    return INSTALLER_STAGES.filter((stage) => stage in timings || stage === current || stage === failed).map((stage) => ({
        key: stage,
        label: label(stage),
        status: stage === failed ? 'failed' : stage === current ? 'active' : 'done',
        seconds: typeof timings[stage] === 'number' ? timings[stage] : null,
    }));
}

/**
 * What the modal shows for a failed job: one friendly sentence first, then
 * for the details view the step it failed in, the server's message and the
 * log tail.
 *
 * @param {Object} intl
 * @param {Object} job
 * @param {Object} [options]
 * @param {String} [options.action]
 * @return {{ summary: String, step: String, message: String, log: Array<String>, rolledBack: Boolean, rollbackIncomplete: Boolean, rebuildRequired: Boolean }}
 */
export function describeInstallerFailure(intl, job = {}, { action = 'install' } = {}) {
    const key = INSTALLER_STEPS[job.step] ?? INSTALLER_STEPS[job.result?.step];
    const rollback = job.result?.rollback ?? {};
    const rolledBack = rollback.performed === true;
    const rollbackIncomplete = rolledBack && Array.isArray(rollback.errors) && rollback.errors.length > 0;
    const verb = action === 'uninstall' ? 'uninstall' : action === 'update' ? 'update' : 'install';
    // Without the record's result (only the socket event arrived) nobody knows whether anything was undone.
    const outcome = rollbackIncomplete ? 'rollback-incomplete' : job.result ? verb : 'generic';
    const summary = intl.t(`registry-bridge.installer.failure.${outcome}`);

    return {
        summary,
        step: key ? intl.t(`registry-bridge.installer.steps.${key}`) : (job.failed_stage ?? job.stage ?? null),
        message: job.message ?? job.result?.message ?? intl.t('registry-bridge.installer.failed'),
        log: job.log ?? job.result?.log ?? [],
        rolledBack,
        rollbackIncomplete,
        rebuildRequired: rollback.console_rebuild_required === true,
    };
}

export default class InstallerJobTracker {
    job = null;
    stopped = false;
    pollErrors = 0;
    lastSeq = 0;
    finalReads = 0;

    /**
     * @param {Object} options
     * @param {Object} options.fetch the fetch service (`get(path, query, options)`)
     * @param {Object} options.socket the socket service (`listen(channel, callback)`, `instance()`)
     * @param {String} options.channel
     * @param {Function} [options.onUpdate] called with the merged job on every change
     * @param {Number} [options.pollInterval] milliseconds between job polls while the socket is not subscribed
     * @param {Number} [options.livePollInterval] milliseconds between safety-net polls while it is
     * @param {Number} [options.finalRetries] reads of the record after the final event before trusting the event alone
     * @param {Function} [options.schedule] `(fn, ms) => handle`, defaults to setTimeout
     * @param {Function} [options.cancel] `(handle) => void`, defaults to clearTimeout
     */
    constructor({ fetch, socket, channel, onUpdate, pollInterval = 4000, livePollInterval = 15000, finalRetries = 3, schedule, cancel }) {
        this.fetch = fetch;
        this.socket = socket;
        this.channel = channel;
        this.onUpdate = onUpdate ?? (() => {});
        this.pollInterval = pollInterval;
        this.livePollInterval = livePollInterval;
        this.finalRetries = finalRetries;
        this.schedule = schedule ?? ((fn, ms) => setTimeout(fn, ms));
        this.cancel = cancel ?? ((handle) => clearTimeout(handle));
        this.buffered = [];
        this.timer = null;
        this.promise = new Promise((resolve) => (this.resolve = resolve));
    }

    /**
     * Subscribe to the job's channel through the socket service. Call before
     * the request that queues the job, so early events are not lost; they are
     * held until track().
     */
    listen() {
        try {
            this.socket?.listen?.(this.channel, (event) => this.handleEvent(event));
        } catch {
            // No socket: polling alone follows the job.
        }
        return this;
    }

    /**
     * Whether progress is arriving over the socket: the client is connected
     * and subscribed to the job's channel. Polling slows down while it is.
     */
    socketLive() {
        try {
            const client = this.socket?.instance?.();
            if (!client || client.state !== 'open') {
                return false;
            }
            return typeof client.isSubscribed === 'function' ? client.isSubscribed(this.channel) : true;
        } catch {
            return false;
        }
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

        // A gap in the numbering means an event was missed (a reconnect): read the record now.
        const seq = Number(event.seq) || 0;
        const missed = seq > 0 && this.lastSeq > 0 && seq > this.lastSeq + 1;
        this.lastSeq = Math.max(this.lastSeq, seq);

        if (FINISHED_STATUSES.includes(event.status)) {
            const final = { ...this.job, status: event.status, progress: 100 };
            for (const key of ['phase', 'percent']) {
                if (event[key] !== undefined) {
                    final[key] = event[key];
                }
            }
            if (event.status === 'failed') {
                Object.assign(final, { step: event.step ?? this.job.step, failed_stage: event.stage, message: event.message, log: event.log ?? [] });
            }
            this.update(final);
            // The record has the full result (stored before this event was sent); the event is enough if it cannot be read.
            this.poll({ final: true });
            return;
        }

        const patch = { status: 'running', position: 0 };
        for (const key of ['step', 'stage', 'progress', 'phase', 'percent', 'seq']) {
            if (event[key] !== undefined) {
                patch[key] = event[key];
            }
        }
        // The percentage never goes backwards, whichever of socket and poll arrived last.
        if (typeof patch.percent === 'number' && typeof this.job.percent === 'number') {
            patch.percent = Math.max(patch.percent, this.job.percent);
        }
        this.update(patch);

        if (missed) {
            this.schedulePoll(0);
        }
    }

    /**
     * Read the job record once. Resolves when this poll is done (not when the
     * job is); the job's outcome is the promise track() returned.
     */
    async poll({ final = false } = {}) {
        if (this.stopped || !this.job) {
            return;
        }

        let record = null;
        try {
            const { job } = await this.fetch.get(`installer/jobs/${this.job.id}`, {}, { namespace: INSTALLER_NAMESPACE });
            this.pollErrors = 0;
            record = job ?? null;
        } catch {
            this.pollErrors++;
        }
        if (this.stopped) {
            return;
        }

        if (record) {
            this.lastSeq = Math.max(this.lastSeq, Number(record.seq) || 0);
            // A final socket event wins over a record that has not caught up yet.
            if (isFinished(record) || !isFinished(this.job)) {
                const percent =
                    typeof record.percent === 'number' && typeof this.job.percent === 'number' && !isFinished(record) ? Math.max(record.percent, this.job.percent) : record.percent;
                this.update(percent === undefined ? record : { ...record, percent });
            }
        }

        if (final || isFinished(this.job)) {
            // The final state comes from the API: give a record that is not finished yet a moment to catch up.
            if (!isFinished(record) && this.finalReads < this.finalRetries) {
                this.finalReads++;
                this.cancel(this.timer);
                this.timer = this.schedule(() => this.poll({ final: true }), 1000);
                return;
            }
            this.finish(this.job);
            return;
        }

        this.schedulePoll();
    }

    schedulePoll(delay = null) {
        this.cancel(this.timer);
        // Slow safety-net polls while the socket delivers; faster ones without it, backing off while the API is
        // unreachable (a restarting container), up to 30 s.
        const base = this.socketLive() ? this.livePollInterval : this.pollInterval;
        const wait = delay ?? Math.min(base * 2 ** Math.min(this.pollErrors, 4), 30000);
        this.timer = this.schedule(() => this.poll(), wait);
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
        if (!this.stopped) {
            this.unsubscribe();
        }
        this.stopped = true;
        this.cancel(this.timer);
        this.timer = null;
    }

    /**
     * Leave the job's channel once the job is followed to its end.
     */
    unsubscribe() {
        try {
            const client = this.socket?.instance?.();
            client?.unsubscribe?.(this.channel);
            client?.closeChannel?.(this.channel);
        } catch {
            // Best effort: a channel left open only costs an idle subscription.
        }
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
 * uninstall) and drive its state: the plain steps, one progress bar and a
 * status line while queued and running, a reload countdown on success, and on
 * failure a friendly sentence (the step, message and log tail go to the
 * details view) with the accept button turned into Retry. `details` always
 * carries the technical stages with their durations. The modal must be shown with
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
 * @param {String} [options.action] install, update or uninstall
 * @param {String} [options.name] the extension name, for the status line
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
    name = '',
    reload = () => window.location.reload(),
    reloadAfter = 3,
    schedule,
    cancel,
    pollInterval,
    livePollInterval,
}) {
    const progress = (job) => ({
        ...describeInstallerJob(intl, job, { action, name }),
        steps: installerPhases(action, job),
        details: technicalStages(intl, action, job),
        phase: 'progress',
        job,
    });

    modal.startLoading();
    modal.setOptions({ installerError: null, ...progress({ status: 'running' }) });

    const tracker = new InstallerJobTracker({ fetch, socket, channel, schedule, cancel, pollInterval, livePollInterval, onUpdate: (job) => modal.setOptions(progress(job)) }).listen();

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
            steps: installerPhases(action, job),
            details: technicalStages(intl, action, job),
            installerError: describeInstallerFailure(intl, job, { action }),
            acceptButtonText: intl.t('registry-bridge.installer.retry'),
            acceptButtonIcon: 'rotate-right',
        });
        return job;
    }

    modal.setOptions({
        ...describeInstallerJob(intl, job, { action, name }),
        phase: 'success',
        steps: installerPhases(action, job),
        details: technicalStages(intl, action, job),
        job,
        secondsLeft: reloadAfter,
    });
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
