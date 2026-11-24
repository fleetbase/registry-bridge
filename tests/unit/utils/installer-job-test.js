import { module, test } from 'qunit';
import InstallerJobTracker, {
    installerChannel,
    installerPhases,
    technicalStages,
    describeInstallerJob,
    describeInstallerFailure,
} from '@fleetbase/registry-bridge-engine/utils/installer-job';

/**
 * The tracker and the modal runner take their services and timers as
 * arguments; these stand-ins record what happened and let a test fire timers
 * and socket events by hand.
 */
function setup() {
    const timers = [];
    const ctx = {
        intl: { t: (key, options) => (options ? `${key} ${JSON.stringify(options)}` : key) },
        socketCallback: null,
        socket: { listen: (channel, callback) => ((ctx.channel = channel), (ctx.socketCallback = callback)) },
        records: [],
        polls: 0,
        fetch: {
            get: async (path, query, options) => {
                ctx.polls++;
                ctx.lastPoll = { path, options };
                const next = ctx.records.length > 1 ? ctx.records.shift() : ctx.records[0];
                if (next instanceof Error) {
                    throw next;
                }
                return { job: next };
            },
        },
        timers,
        schedule: (fn, ms) => timers.push({ fn, ms }) - 1,
        cancel: (handle) => {
            if (handle !== null && handle !== undefined && timers[handle]) {
                timers[handle].cancelled = true;
            }
        },
        async fireTimers() {
            const pending = timers.filter((timer) => !timer.cancelled && !timer.fired);
            for (const timer of pending) {
                timer.fired = true;
                await timer.fn();
            }
        },
        emit(event) {
            return ctx.socketCallback(event);
        },
    };
    ctx.tracker = (options = {}) => new InstallerJobTracker({ fetch: ctx.fetch, socket: ctx.socket, channel: 'install.c1.e1', schedule: ctx.schedule, cancel: ctx.cancel, ...options });
    ctx.modal = {
        options: {},
        loading: [],
        startLoading: () => ctx.modal.loading.push('start'),
        stopLoading: () => ctx.modal.loading.push('stop'),
        setOption: (key, value) => (ctx.modal.options[key] = value),
        setOptions: (options) => Object.assign(ctx.modal.options, options),
    };

    return ctx;
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

module('Unit | Utility | installer-job', function () {
    test('uninstalls publish on uninstall.*, installs and updates on install.*', function (assert) {
        assert.strictEqual(installerChannel('install', 'c1', 'e1'), 'install.c1.e1');
        assert.strictEqual(installerChannel('update', 'c1', 'e1'), 'install.c1.e1');
        assert.strictEqual(installerChannel('uninstall', 'c1', 'e1'), 'uninstall.c1.e1');
    });

    test('job states map to a progress bar title and percentage', function (assert) {
        const { intl } = setup();

        assert.deepEqual(describeInstallerJob(intl, { status: 'queued', position: 0 }), { stepDescription: 'registry-bridge.installer.queued-next', progress: 3 });
        assert.deepEqual(describeInstallerJob(intl, { status: 'queued', position: 2 }), { stepDescription: 'registry-bridge.installer.queued-behind {"count":2}', progress: 3 });
        assert.deepEqual(describeInstallerJob(intl, { status: 'running', phase: 'install', percent: 42, step: 'server.install' }, { action: 'install', name: 'Pallet' }), {
            stepDescription: 'registry-bridge.installer.status.install {"name":"Pallet"}',
            progress: 42,
        });
        assert.strictEqual(
            describeInstallerJob(intl, { status: 'running', phase: 'install', percent: 42 }, { action: 'update', name: 'Pallet' }).stepDescription,
            'registry-bridge.installer.status.update {"name":"Pallet"}'
        );
        assert.strictEqual(describeInstallerJob(intl, { status: 'running', step: 'console.build', stage: 'build', percent: 50 }).stepDescription, 'registry-bridge.installer.status.build');
        assert.strictEqual(describeInstallerJob(intl, { status: 'running', step: 'rollback', percent: 60 }).stepDescription, 'registry-bridge.installer.status.rollback');
        assert.strictEqual(describeInstallerJob(intl, { status: 'running', step: 'server.install', progress: 50 }).progress, 62, 'older records without percent: estimated from the phase');
        assert.deepEqual(describeInstallerJob(intl, { status: 'running' }), { stepDescription: 'registry-bridge.installer.starting', progress: 3 });
        assert.strictEqual(describeInstallerJob(intl, { status: 'completed' }).progress, 100);
    });

    test('a failure is described by its step, message, log and rollback outcome', function (assert) {
        const { intl } = setup();
        const failure = describeInstallerFailure(intl, {
            status: 'failed',
            step: 'engine.install',
            failed_stage: 'engine',
            message: 'The prebuilt engine is not compatible with this console',
            log: ['$ composer require acme/widgets', '!! engine failed'],
            result: { rollback: { performed: true, console_rebuild_required: false } },
        });

        assert.deepEqual(failure, {
            summary: 'registry-bridge.installer.failure.install',
            step: 'registry-bridge.installer.steps.engine-install',
            message: 'The prebuilt engine is not compatible with this console',
            log: ['$ composer require acme/widgets', '!! engine failed'],
            rolledBack: true,
            rollbackIncomplete: false,
            rebuildRequired: false,
        });
        assert.strictEqual(
            describeInstallerFailure(intl, { status: 'failed', result: { rollback: { performed: true, errors: ['composer install failed'] } } }).summary,
            'registry-bridge.installer.failure.rollback-incomplete'
        );
        assert.strictEqual(describeInstallerFailure(intl, { status: 'failed', message: 'socket only' }).summary, 'registry-bridge.installer.failure.generic');
        assert.strictEqual(describeInstallerFailure(intl, { failed_stage: 'interrupted' }).step, 'interrupted');
    });

    test('socket events drive progress and the completion event resolves with the job record', async function (assert) {
        const ctx = setup();
        const updates = [];
        const tracker = ctx.tracker({ onUpdate: (job) => updates.push({ ...job }) }).listen();
        assert.strictEqual(ctx.channel, 'install.c1.e1');

        const done = tracker.track({ id: 'j1', status: 'queued', position: 0 });
        ctx.emit({ job: 'j1', step: 'server.install', stage: 'composer', progress: 40, status: 'running' });
        ctx.emit({ job: 'other', step: 'console.build', progress: 90, status: 'running' });
        assert.deepEqual(updates.at(-1), { id: 'j1', status: 'running', position: 0, step: 'server.install', stage: 'composer', progress: 40 }, 'events of other jobs are ignored');

        ctx.records = [{ id: 'j1', status: 'completed', progress: 100, result: { mode: 'runtime' } }];
        ctx.emit({ job: 'j1', step: 'install.complete', progress: 100, status: 'completed' });
        const job = await done;

        assert.strictEqual(job.status, 'completed');
        assert.deepEqual(job.result, { mode: 'runtime' }, 'the record fetched after the final event');
        assert.deepEqual(ctx.lastPoll, { path: 'installer/jobs/j1', options: { namespace: '~registry/v1' } });
    });

    test('events that arrive before the job id is known are replayed', async function (assert) {
        const ctx = setup();
        const tracker = ctx.tracker().listen();

        ctx.emit({ job: 'j1', step: 'release.resolve', progress: 100, status: 'running' });
        tracker.track({ id: 'j1', status: 'queued' });

        assert.strictEqual(tracker.job.step, 'release.resolve');
        assert.strictEqual(tracker.job.status, 'running');
        tracker.stop();
    });

    test('polling follows the job when the socket is silent, including the queue position', async function (assert) {
        const ctx = setup();
        const updates = [];
        const tracker = ctx.tracker({ onUpdate: (job) => updates.push({ ...job }) }).listen();
        const done = tracker.track({ id: 'j1', status: 'queued', position: 2 });

        ctx.records = [
            { id: 'j1', status: 'queued', position: 1 },
            { id: 'j1', status: 'running', step: 'cache.clear', progress: 0, position: 0 },
            { id: 'j1', status: 'completed' },
        ];
        await ctx.fireTimers();
        assert.strictEqual(updates.at(-1).position, 1);
        await ctx.fireTimers();
        assert.strictEqual(updates.at(-1).step, 'cache.clear');
        await ctx.fireTimers();

        assert.strictEqual((await done).status, 'completed');
        assert.strictEqual(ctx.polls, 3);
        assert.strictEqual(ctx.timers.filter((timer) => !timer.fired && !timer.cancelled).length, 0, 'no poll is left scheduled');
    });

    test('polling backs off while the API is unreachable and recovers', async function (assert) {
        const ctx = setup();
        const tracker = ctx.tracker({ pollInterval: 1000 }).listen();
        const done = tracker.track({ id: 'j1', status: 'running' });
        assert.strictEqual(ctx.timers.at(-1).ms, 1000);

        ctx.records = [new Error('502'), new Error('502'), { id: 'j1', status: 'completed' }];
        await ctx.fireTimers();
        assert.strictEqual(ctx.timers.at(-1).ms, 2000);
        await ctx.fireTimers();
        assert.strictEqual(ctx.timers.at(-1).ms, 4000);
        await ctx.fireTimers();

        assert.strictEqual((await done).status, 'completed');
    });

    test('a failure event keeps its message and log when the record cannot be read', async function (assert) {
        const ctx = setup();
        const tracker = ctx.tracker().listen();
        const done = tracker.track({ id: 'j1', status: 'running' });

        ctx.records = [new Error('offline')];
        ctx.emit({ job: 'j1', status: 'failed', step: 'server.install', stage: 'composer', message: 'composer exited with code 2', log: ['Problem 1'] });
        // The record is read a few more times before the event alone is trusted.
        for (let i = 0; i < 4; i++) {
            await settle();
            await ctx.fireTimers();
        }
        const job = await done;
        assert.strictEqual(ctx.polls, 4, 'one read on the final event and three retries');

        assert.strictEqual(job.status, 'failed');
        assert.strictEqual(job.failed_stage, 'composer');
        assert.strictEqual(job.step, 'server.install');
        assert.strictEqual(job.message, 'composer exited with code 2');
        assert.deepEqual(job.log, ['Problem 1']);
    });

    test('a stale running record does not undo a final socket event', async function (assert) {
        const ctx = setup();
        const tracker = ctx.tracker().listen();
        const done = tracker.track({ id: 'j1', status: 'running' });

        ctx.records = [{ id: 'j1', status: 'running', step: 'install.record' }];
        ctx.emit({ job: 'j1', status: 'completed', step: 'install.complete' });
        for (let i = 0; i < 4; i++) {
            await settle();
            await ctx.fireTimers();
        }

        assert.strictEqual((await done).status, 'completed');
    });

    test('responses without a job, or with a finished one, resolve straight away', async function (assert) {
        const ctx = setup();

        assert.strictEqual((await ctx.tracker().listen().track(undefined)).status, 'completed', 'preinstalled instances return no job');
        assert.strictEqual((await ctx.tracker().listen().track({ id: 'j2', status: 'completed' })).id, 'j2', 'synchronous installs return a finished job');
        assert.strictEqual(ctx.polls, 0);
    });

    test('installer stages map onto the plain steps of the modal', function (assert) {
        const statuses = (action, job) => installerPhases(action, job).map((group) => `${group.key}:${group.status}`);

        assert.deepEqual(statuses('install', {}), ['prepare:pending', 'download:pending', 'install:pending', 'finish:pending']);
        assert.deepEqual(statuses('install', { status: 'queued' }), ['prepare:pending', 'download:pending', 'install:pending', 'finish:pending']);
        assert.deepEqual(statuses('install', { status: 'running' }), ['prepare:active', 'download:pending', 'install:pending', 'finish:pending'], 'a started job is on the first step');
        assert.deepEqual(statuses('install', { status: 'running', step: 'release.download' }), ['prepare:done', 'download:active', 'install:pending', 'finish:pending']);
        assert.deepEqual(statuses('update', { status: 'running', phase: 'install', step: 'engine.install' }), ['prepare:done', 'download:done', 'install:active', 'finish:pending']);
        assert.deepEqual(statuses('install', { status: 'running', step: 'cache.clear' }), ['prepare:done', 'download:done', 'install:done', 'finish:active']);
        assert.deepEqual(statuses('uninstall', { status: 'running', step: 'engine.uninstall' }), ['prepare:done', 'remove:active', 'finish:pending']);
        assert.deepEqual(statuses('install', { status: 'completed' }), ['prepare:done', 'download:done', 'install:done', 'finish:done']);
        assert.deepEqual(statuses('install', { status: 'failed', step: 'engine.install', failed_stage: 'engine' }), ['prepare:done', 'download:done', 'install:failed', 'finish:pending']);
        assert.deepEqual(
            statuses('install', { status: 'failed', step: 'rollback', result: { step: 'console.build', failed_phase: 'install' } }),
            ['prepare:done', 'download:done', 'install:failed', 'finish:pending'],
            'a rollback reports the step that failed'
        );
    });

    test('the technical stages carry their status and duration for the details view', function (assert) {
        const { intl } = setup();
        const stages = technicalStages(intl, 'uninstall', { status: 'running', stage: 'engine', timings: { resolve: 0.2, composer: 7.8 } });

        assert.deepEqual(
            stages.map((stage) => [stage.key, stage.status, stage.seconds, stage.label]),
            [
                ['resolve', 'done', 0.2, 'registry-bridge.installer.steps.resolve'],
                ['composer', 'done', 7.8, 'registry-bridge.installer.steps.server-uninstall'],
                ['engine', 'active', null, 'registry-bridge.installer.steps.engine-uninstall'],
            ]
        );
    });

    test('polling slows down while the socket delivers and reads the record at once after a missed event', async function (assert) {
        const ctx = setup();
        let subscribed = true;
        ctx.socket.instance = () => ({ state: 'open', isSubscribed: () => subscribed, unsubscribe: (channel) => (ctx.unsubscribed = channel), closeChannel: () => {} });
        const tracker = ctx.tracker().listen();
        const done = tracker.track({ id: 'j1', status: 'queued' });
        assert.strictEqual(ctx.timers.at(-1).ms, 15000, 'a safety-net poll while the socket is subscribed');

        ctx.emit({ job: 'j1', seq: 1, status: 'running', phase: 'prepare', percent: 2 });
        ctx.emit({ job: 'j1', seq: 3, status: 'running', phase: 'install', percent: 40 });
        assert.strictEqual(ctx.timers.at(-1).ms, 0, 'event 2 was missed: read the record now');

        subscribed = false;
        ctx.records = [{ id: 'j1', status: 'running', phase: 'install', percent: 30, seq: 3 }];
        await ctx.timers.at(-1).fn();
        assert.strictEqual(tracker.job.percent, 40, 'the bar never goes backwards');
        assert.strictEqual(ctx.timers.at(-1).ms, 4000, 'faster polls without the socket');

        ctx.records = [
            { id: 'j1', status: 'running', seq: 9 },
            { id: 'j1', status: 'completed', result: { timings: { composer: 2 } } },
        ];
        ctx.emit({ job: 'j1', seq: 10, status: 'completed', phase: 'finish', percent: 100 });
        await settle();
        assert.strictEqual(ctx.timers.at(-1).ms, 1000, 'the record is not finished yet: read it again shortly');
        await ctx.timers.at(-1).fn();

        const job = await done;
        assert.deepEqual(job.result, { timings: { composer: 2 } }, 'the final state comes from the API');
        assert.strictEqual(ctx.unsubscribed, 'install.c1.e1', 'the channel is left once the job is done');
    });
});
