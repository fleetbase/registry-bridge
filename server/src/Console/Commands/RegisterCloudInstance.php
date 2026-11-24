<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Support\CloudRegistration;
use Illuminate\Console\Command;

/**
 * Register the cloud console's API with the Registry Service as the one cloud instance (`registry:init` runs it on
 * boot and the scheduler every five minutes). Does nothing while the stored token is accepted; `--force` rotates it.
 */
class RegisterCloudInstance extends Command
{
    protected $signature = 'registry:cloud-register
                            {--force : Register even when the stored token is accepted (rotates it)}
                            {--json : Print the result as JSON}';

    protected $description = 'Register this API with the Registry Service as the cloud instance (signed with the platform secret).';

    public function handle(): int
    {
        try {
            $result = CloudRegistration::ensure((bool) $this->option('force'));
        } catch (RegistryException $e) {
            return $this->report(['status' => 'failed', 'message' => $e->getMessage(), 'summary' => null], self::FAILURE);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return $this->report(['status' => 'failed', 'message' => 'Another process is registering this instance; try again in a minute.', 'summary' => null], self::FAILURE);
        }

        return $this->report($result, in_array($result['status'], [CloudRegistration::STATUS_DISABLED, CloudRegistration::STATUS_UNAVAILABLE], true) ? self::FAILURE : self::SUCCESS);
    }

    /**
     * @param array{status: string, message: string, summary: array<string, mixed>|null} $result
     */
    protected function report(array $result, int $code): int
    {
        if ($this->option('json')) {
            $summary = $result['summary'] ?? null;
            unset($summary['token']);
            $this->line((string) json_encode([...$result, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $code;
        }

        $code === self::SUCCESS ? $this->info($result['message']) : $this->error($result['message']);

        return $code;
    }
}
