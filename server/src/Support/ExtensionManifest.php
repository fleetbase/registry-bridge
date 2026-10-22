<?php

namespace Fleetbase\RegistryBridge\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates an `extension.json` manifest against the bundled JSON Schema (draft 2020-12).
 */
class ExtensionManifest
{
    /**
     * Absolute path of the extension manifest schema.
     */
    public static function schemaPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/schemas/extension.schema.json';
    }

    /**
     * Validate a decoded manifest.
     *
     * @param object|array<string, mixed> $manifest the decoded `extension.json`
     *
     * @return array<string, array<int, string>> validation errors keyed by JSON pointer (`/version`), empty when valid
     */
    public static function validate(object|array $manifest): array
    {
        // Opis expects JSON objects as stdClass, so round-trip anything else through JSON.
        $data = $manifest instanceof \stdClass ? $manifest : json_decode((string) json_encode($manifest));

        $validator = new Validator();
        $validator->setMaxErrors(25);

        $schema = json_decode((string) file_get_contents(static::schemaPath()), false);
        if (!is_object($schema)) {
            throw new \RuntimeException('Unable to load extension manifest schema from ' . static::schemaPath());
        }

        $result = $validator->validate($data, $schema);
        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if ($error === null) {
            return ['/' => ['Manifest is invalid.']];
        }

        /** @var array<string, array<int, string>> $errors */
        $errors = (new ErrorFormatter())->formatKeyed($error);

        return $errors;
    }

    /**
     * Flatten validation errors into `pointer: message` lines.
     *
     * @param array<string, array<int, string>> $errors
     *
     * @return array<int, string>
     */
    public static function flatten(array $errors): array
    {
        $lines = [];
        foreach ($errors as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = trim($pointer . ': ' . $message);
            }
        }

        return $lines;
    }
}
