<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Register this instance with the Registry Service from a server shell (`flb instance register`).
 *
 * Pass the registration code on stdin with `--code-stdin` to keep it out of the process list. The instance
 * token is stored encrypted in settings and never printed.
 */
class RegisterInstance extends Command
{
    protected $signature = 'registry:register
                            {--code= : Registration code from your registry organization}
                            {--code-stdin : Read the registration code from stdin}
                            {--service-url= : Registry Service URL (defaults to the configured one)}
                            {--name= : Instance name (defaults to the app name)}
                            {--host= : Instance URL (defaults to the app URL)}
                            {--console-version= : Console version (defaults to the console package.json)}
                            {--json : Print the stored registration as JSON}';

    protected $description = 'Register this instance with the Registry Service using a registration code.';

    /**
     * The registration is resolved from the container so tests can replace its settings writer.
     */
    public function handle(InstanceRegistration $registration): int
    {
        $code = $this->code();
        if ($code === null) {
            return $this->refuse('Pass the registration code with exactly one of --code or --code-stdin.');
        }

        try {
            $summary = $registration::register(
                $code,
                $this->stringOption('service-url'),
                $this->stringOption('name'),
                $this->stringOption('host'),
                $this->stringOption('console-version')
            );
        } catch (RegistryException $e) {
            return $this->refuse($e->getMessage());
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $organization = is_array($summary['organization'] ?? null) ? $summary['organization'] : [];
            $this->info(sprintf('Registered %s (%s) with %s.', static::text($summary['name'] ?? null, 'this instance'), static::text($summary['id'] ?? null, 'unknown id'), static::text($organization['name'] ?? $organization['slug'] ?? null, 'the registry organization')));
            $this->line(sprintf('Registry Service: %s', static::text($summary['service_url'] ?? null)));
            $this->line(sprintf('Instance token:   %s (stored encrypted)', static::text($summary['token_hint'] ?? null, 'stored')));
        }

        return self::SUCCESS;
    }

    /**
     * The code from --code or stdin, trimmed; null unless exactly one of them was given.
     */
    protected function code(): ?string
    {
        $option = $this->stringOption('code');
        $stdin  = (bool) $this->option('code-stdin');
        if (($option !== null) === $stdin) {
            return null;
        }

        if ($option !== null) {
            return $option;
        }

        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= fopen('php://stdin', 'r');
        $code   = is_resource($stream) ? stream_get_contents($stream) : false;

        return is_string($code) ? trim($code) : '';
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected static function text(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    protected function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
