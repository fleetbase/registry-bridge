import { module, test } from 'qunit';
import InstallerJobTracker, {
    installerChannel,
    installerStepGroups,
    describeInstallerJob,
    describeInstallerFailure,
    runInstallerJob,
    countdown,
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

        assert.deepEqual(describeInstallerJob(intl, { status: 'queued', position: 0 }), { stepDescription: 'registry-bridge.installer.queued-next', progress: 5 });
        assert.deepEqual(describeInstallerJob(intl, { status: 'queued', position: 2 }), { stepDescription: 'registry-bridge.installer.queued-behind {"count":2}', progress: 5 });
        assert.deepEqual(describeInstallerJob(intl, { status: 'running', step: 'server.install', progress: 75 }), {
            stepDescription: 'registry-bridge.installer.steps.server-install',
            progress: 75,
        });
        assert.deepEqual(describeInstallerJob(intl, { status: 'running', step: 'console.build', progress: 0 }), {
            stepDescription: 'registry-bridge.installer.steps.console-build',
            progress: 5,
        });
        assert.deepEqual(describeInstallerJob(intl, { status: 'running', step: 'something.new' }), { stepDescription: 'registry-bridge.installer.starting', progress: 5 });
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
            step: 'registry-bridge.installer.steps.engine-install',
            message: 'The prebuilt engine is not compatible with this console',
            log: ['$ composer require acme/widgets', '!! engine failed'],
            rolledBack: true,
            rebuildRequired: false,
        });
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
        const job = await done;

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

        assert.strictEqual((await done).status, 'completed');
    });

    test('responses without a job, or with a finished one, resolve straight away', async function (assert) {
        const ctx = setup();

        assert.strictEqual((await ctx.tracker().listen().track(undefined)).status, 'completed', 'preinstalled instances return no job');
        assert.strictEqual((await ctx.tracker().listen().track({ id: 'j2', status: 'completed' })).id, 'j2', 'synchronous installs return a finished job');
        assert.strictEqual(ctx.polls, 0);
    });

    test('countdown ticks every second and then calls done', function (assert) {
        const ctx = setup();
        const ticks = [];
        let done = false;

        countdown(
            3,
            (left) => ticks.push(left),
            () => (done = true),
            ctx.schedule,
            ctx.cancel
        );
        ctx.timers.forEach((timer) => assert.strictEqual(timer.ms, 1000));
        for (let i = 0; i < 3; i++) {
            ctx.timers.filter((timer) => !timer.fired).forEach((timer) => ((timer.fired = true), timer.fn()));
        }

        assert.deepEqual(ticks, [3, 2, 1]);
        assert.true(done);
    });

    test('runInstallerJob shows the queue, the steps and a reload countdown on success', async function (assert) {
        const ctx = setup();
        let reloaded = false;

        const running = runInstallerJob({
            ...ctx,
            channel: 'install.c1.e1',
            request: async () => ({ status: 'queued', job: { id: 'j1', status: 'queued', position: 1 } }),
            reload: () => (reloaded = true),
        });
        await settle();
        assert.strictEqual(ctx.modal.options.stepDescription, 'registry-bridge.installer.queued-behind {"count":1}');

        ctx.emit({ job: 'j1', step: 'engine.install', progress: 80, status: 'running' });
        assert.deepEqual([ctx.modal.options.stepDescription, ctx.modal.options.progress], ['registry-bridge.installer.steps.engine-install', 80]);

        ctx.records = [{ id: 'j1', status: 'completed' }];
        ctx.emit({ job: 'j1', status: 'completed', step: 'install.complete' });
        const job = await running;

        assert.strictEqual(job.status, 'completed');
        assert.strictEqual(ctx.modal.options.progress, 100);
        assert.strictEqual(ctx.modal.options.stepDescription, 'registry-bridge.installer.reloading {"seconds":3}');
        assert.false(reloaded, 'the console reloads only after the countdown');
        await ctx.fireTimers();
        await ctx.fireTimers();
        await ctx.fireTimers();
        assert.true(reloaded);
        assert.deepEqual(ctx.modal.loading, ['start'], 'the modal stays busy until the reload');
    });

    test('runInstallerJob keeps the modal open on failure with the error and a Retry button', async function (assert) {
        const ctx = setup();
        let reloaded = false;

        const running = runInstallerJob({
            ...ctx,
            channel: 'install.c1.e1',
            request: async () => ({ status: 'queued', job: { id: 'j1', status: 'queued', position: 0 } }),
            reload: () => (reloaded = true),
        });
        await settle();
        ctx.records = [
            {
                id: 'j1',
                status: 'failed',
                step: 'server.install',
                failed_stage: 'composer',
                message: 'composer exited with code 2',
                log: ['Problem 1'],
                result: { rollback: { performed: true } },
            },
        ];
        ctx.emit({ job: 'j1', status: 'failed', step: 'server.install', stage: 'composer', message: 'composer exited with code 2', log: ['Problem 1'] });
        const job = await running;

        assert.strictEqual(job.status, 'failed');
        assert.deepEqual(ctx.modal.loading, ['start', 'stop']);
        assert.strictEqual(ctx.modal.options.acceptButtonText, 'registry-bridge.installer.retry');
        assert.strictEqual(ctx.modal.options.progress, 0);
        assert.deepEqual(ctx.modal.options.installerError, {
            step: 'registry-bridge.installer.steps.server-install',
            message: 'composer exited with code 2',
            log: ['Problem 1'],
            rolledBack: true,
            rebuildRequired: false,
        });
        await ctx.fireTimers();
        assert.false(reloaded);
    });

    test('runInstallerJob clears the previous error when retrying and rethrows refused requests', async function (assert) {
        const ctx = setup();
        ctx.modal.options.installerError = { message: 'old' };
        const refused = new Error('This extension is already installed.');

        try {
            await runInstallerJob({ ...ctx, channel: 'install.c1.e1', request: async () => Promise.reject(refused) });
            assert.ok(false, 'should reject');
        } catch (error) {
            assert.strictEqual(error, refused);
        }

        assert.strictEqual(ctx.modal.options.installerError, null);
        assert.deepEqual(ctx.modal.loading, ['start', 'stop']);
        assert.strictEqual(ctx.polls, 0);
    });

    test('installer steps group into the three named steps of the modal', function (assert) {
        const statuses = (action, job) => installerStepGroups(action, job).map((group) => `${group.key}:${group.status}`);

        assert.deepEqual(statuses('install', {}), ['server:pending', 'engine:pending', 'build:pending']);
        assert.deepEqual(statuses('install', { status: 'queued' }), ['server:pending', 'engine:pending', 'build:pending']);
        assert.deepEqual(statuses('install', { status: 'running' }), ['server:active', 'engine:pending', 'build:pending'], 'a started job is on the first step');
        assert.deepEqual(statuses('install', { status: 'running', step: 'release.download' }), ['server:active', 'engine:pending', 'build:pending']);
        assert.deepEqual(statuses('update', { status: 'running', step: 'engine.install' }), ['server:done', 'engine:active', 'build:pending']);
        assert.deepEqual(statuses('install', { status: 'running', step: 'cache.clear' }), ['server:done', 'engine:done', 'build:active']);
        assert.deepEqual(statuses('uninstall', { status: 'running', step: 'engine.uninstall' }), ['server:done', 'engine:active', 'build:pending']);
        assert.deepEqual(statuses('install', { status: 'completed' }), ['server:done', 'engine:done', 'build:done']);
        assert.deepEqual(statuses('install', { status: 'failed', step: 'engine.install' }), ['server:done', 'engine:failed', 'build:pending']);
        assert.deepEqual(
            statuses('install', { status: 'failed', step: 'rollback', result: { step: 'console.build' } }),
            ['server:done', 'engine:done', 'build:failed'],
            'a rollback reports the step that failed'
        );
    });

    test('runInstallerJob moves the modal through progress and success with named steps and a cancellable reload', async function (assert) {
        const ctx = setup();
        let reloaded = false;

        const running = runInstallerJob({
            ...ctx,
            action: 'update',
            channel: 'install.c1.e1',
            request: async () => ({ status: 'queued', job: { id: 'j1', status: 'queued', position: 0 } }),
            reload: () => (reloaded = true),
            reloadAfter: 2,
        });
        await settle();
        assert.strictEqual(ctx.modal.options.phase, 'progress');

        ctx.emit({ job: 'j1', step: 'engine.install', progress: 50, status: 'running' });
        assert.deepEqual(
            ctx.modal.options.steps.map((group) => group.status),
            ['done', 'active', 'pending']
        );

        ctx.records = [{ id: 'j1', status: 'completed' }];
        ctx.emit({ job: 'j1', status: 'completed' });
        await running;

        assert.strictEqual(ctx.modal.options.phase, 'success');
        assert.strictEqual(ctx.modal.options.secondsLeft, 2);
        assert.deepEqual(
            ctx.modal.options.steps.map((group) => group.status),
            ['done', 'done', 'done']
        );

        ctx.modal.options.cancelReload();
        await ctx.fireTimers();
        await ctx.fireTimers();
        assert.false(reloaded, 'Wait, stay here stops the reload');
        assert.true(ctx.modal.options.reloadCancelled);
        assert.deepEqual(ctx.modal.loading, ['start', 'stop']);
    });

    test('runInstallerJob reports the failed step on the failure phase', async function (assert) {
        const ctx = setup();
        ctx.records = [{ id: 'j1', status: 'failed', step: 'engine.install', message: 'npm install exited with code 1', result: { step: 'engine.install' } }];

        const job = await runInstallerJob({
            ...ctx,
            channel: 'install.c1.e1',
            request: async () => ({ job: { id: 'j1', status: 'failed', step: 'engine.install', message: 'npm install exited with code 1' } }),
        });

        assert.strictEqual(job.status, 'failed');
        assert.strictEqual(ctx.modal.options.phase, 'failure');
        assert.deepEqual(
            ctx.modal.options.steps.map((group) => group.status),
            ['done', 'failed', 'pending']
        );
        assert.strictEqual(ctx.modal.options.installerError.message, 'npm install exited with code 1');
    });

    test('a refused request returns the modal to its confirm phase', async function (assert) {
        const ctx = setup();
        try {
            await runInstallerJob({ ...ctx, channel: 'install.c1.e1', request: async () => Promise.reject(new Error('refused')) });
        } catch {
            // expected
        }
        assert.strictEqual(ctx.modal.options.phase, 'confirm');
    });
});
