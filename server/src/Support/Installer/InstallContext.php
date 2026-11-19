<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * State of one install, uninstall or update while ExtensionInstaller runs it.
 */
class InstallContext
{
    public string $id;
    public string $action;
    public ExtensionRef $extension;
    public ?string $version;
    public string $company;
    public ?string $user;
    public string $channel;
    public InstallLog $log;
    public ?string $stage                = null;
    public ?string $step                 = null;
    public ?Release $release             = null;
    public ?EngineRelease $engineRelease = null;
    public ?Snapshot $snapshot           = null;

    /** @var array<string, string> local paths of downloaded artifacts by role */
    public array $downloads = [];

    /** @var array<int, string> */
    public array $completed = [];

    /** Whether the InstallReporter accepted the start of this job (so a failure is reported too). */
    public bool $reportedStart = false;

    /** @var array<string, mixed> */
    public array $result = [];

    /** @var callable(array<string, mixed>): void|null */
    private $onProgress;

    /**
     * @param array<string, mixed> $job the job record
     */
    public function __construct(array $job, ?callable $onProgress = null)
    {
        $this->id         = (string) $job['id'];
        $this->action     = (string) ($job['action'] ?? 'install');
        $this->extension  = ExtensionRef::fromArray((array) ($job['extension'] ?? []));
        $this->version    = isset($job['version']) && $job['version'] !== '' ? (string) $job['version'] : null;
        $this->company    = (string) ($job['company'] ?? '');
        $this->user       = isset($job['user']) && $job['user'] !== '' ? (string) $job['user'] : null;
        $this->channel    = static::channel($this->action, $this->company, $this->extension->uuid);
        $this->log        = new InstallLog();
        $this->onProgress = $onProgress;
    }

    /**
     * The resolved release (set by the resolve stage).
     */
    public function release(): Release
    {
        return $this->release ?? throw new \LogicException('The release is resolved in the first stage.');
    }

    /**
     * The snapshot (set by the snapshot stage, before anything changes).
     */
    public function snapshot(): Snapshot
    {
        return $this->snapshot ?? throw new \LogicException('Nothing may change before the snapshot is taken.');
    }

    /**
     * The socket channel the console listens on: `install.{company}.{extension}`,
     * or `uninstall.{company}.{extension}` for uninstalls (as the console's
     * Installed page subscribes today).
     */
    public static function channel(string $action, string $company, string $extensionUuid): string
    {
        return implode('.', [$action === 'uninstall' ? 'uninstall' : 'install', $company, $extensionUuid]);
    }

    /**
     * The `process` field of a socket event, as the pre-job installer sent it.
     */
    public function process(string $step): string
    {
        return match ($step) {
            'console.build' => 'build',
            'rollback'      => 'rollback',
            default         => $this->action === 'uninstall' ? 'uninstall' : 'install',
        };
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function notify(array $patch): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($patch);
        }
    }
}
