<?php

namespace Fleetbase\RegistryBridge\Exceptions;

/**
 * The Registry Service answered with an error (4xx/5xx) in its `{"errors": [...]}` envelope.
 *
 * Unlike `RegistryUnavailableException`, the service was reachable; the request itself was refused
 * (invalid registration code, paid extension without a licence, hosting mismatch, …).
 *
 * @phpstan-consistent-constructor
 */
class RegistryServiceException extends RegistryException
{
    /**
     * @param array<int, string>                $errors
     * @param array<string, array<int, string>> $fields
     */
    public function __construct(string $message, protected int $status = 0, protected array $errors = [], protected array $fields = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Build from a service response body.
     */
    public static function fromResponse(int $status, mixed $body): static
    {
        $errors = [];
        $fields = [];

        if (is_array($body)) {
            foreach ((array) ($body['errors'] ?? []) as $error) {
                if (is_string($error) && $error !== '') {
                    $errors[] = $error;
                }
            }

            if (isset($body['error']) && is_string($body['error']) && $errors === []) {
                $errors[] = $body['error'];
            }

            if (isset($body['fields']) && is_array($body['fields'])) {
                $fields = array_filter($body['fields'], 'is_array');
            }
        }

        $message = $errors !== [] ? implode(' ', $errors) : 'The registry service responded with HTTP ' . $status . '.';

        return new static($message, $status, $errors, $fields);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
