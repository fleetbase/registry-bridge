<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\EngineCompatChecker;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProcessRunner;

/**
 * Runs @fleetbase/ember-core's `bin/check-engine-compat.js --json` (ember-core#99).
 * Exit 0 is compatible, 1 is not; anything else is a usage error.
 */
class NodeEngineCompat implements EngineCompatChecker
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $script,
        private readonly string $cwd,
        private readonly string $node = 'node',
    ) {
    }

    public function check(string $manifestPath, string $hostModulesPath, string $policy = 'minor'): CompatResult
    {
        $result = $this->runner->run([$this->node, $this->script, '--manifest', $manifestPath, '--host', $hostModulesPath, '--policy', $policy, '--json'], $this->cwd, null, 300);

        $json = $this->decode($result->output);
        if (!in_array($result->exitCode, [0, 1], true) || $json === null) {
            throw new \RuntimeException(sprintf('check-engine-compat failed (exit %d): %s', $result->exitCode, trim($result->output)));
        }

        return CompatResult::fromArray($json + ['compatible' => $result->exitCode === 0]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $output): ?array
    {
        $start = strpos($output, '{');
        $end   = strrpos($output, '}');
        if ($start === false || $end === false) {
            return null;
        }
        $data = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }
}
