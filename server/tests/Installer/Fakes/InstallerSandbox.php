<?php

namespace Fleetbase\RegistryBridge\Tests\Installer\Fakes;

use Fleetbase\RegistryBridge\Support\Installer\Artifact;
use Fleetbase\RegistryBridge\Support\Installer\ArtifactDownloader;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\EngineCompatChecker;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionInstaller;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\Files;
use Fleetbase\RegistryBridge\Support\Installer\InstallerConfig;
use Fleetbase\RegistryBridge\Support\Installer\PhpEngineCompat;
use Fleetbase\RegistryBridge\Support\Installer\Release;
use Fleetbase\RegistryBridge\Support\Installer\RuntimeEngineManifest;

/**
 * A throwaway API checkout and console build on disk, plus the fakes an
 * ExtensionInstaller needs. Composer and pnpm are FakeProcessRunner handlers
 * that edit the manifests like the real tools.
 */
class InstallerSandbox
{
    public const ENGINE   = '@acme/widgets-engine';
    public const COMPOSER = 'acme/widgets-api';
    public const UI       = '0.4.6';
    public const CORE     = '0.3.26';

    public string $root;
    public string $api;
    public string $console;
    public string $runtime;
    public FakeProcessRunner $runner;
    public RecordingPublisher $publisher;
    public FakeReleaseSource $releases;
    public FakeInstallRecorder $recorder;
    public ExtensionRef $extension;
    public ?EngineCompatChecker $compat = null;

    /** @var array<int, string> modules the console build defines */
    public array $hostModules = ['@fleetbase/ember-ui/components/button', '@fleetbase/ember-core/services/fetch', 'ember-source/index'];

    /** @var array<string, mixed> */
    public array $configOverrides = [];

    public function __construct()
    {
        $this->root    = sys_get_temp_dir() . '/registry-installer-test-' . bin2hex(random_bytes(6));
        $this->api     = $this->root . '/api';
        $this->console = $this->root . '/console';
        $this->runtime = $this->console . '/dist/engines-dist';

        Files::write($this->api . '/composer.json', json_encode(['require' => ['fleetbase/core-api' => '^1.6']], JSON_PRETTY_PRINT));
        Files::write($this->api . '/composer.lock', '{"packages":["fleetbase/core-api"]}');
        Files::write($this->console . '/package.json', json_encode(['dependencies' => ['@fleetbase/ember-core' => self::CORE]], JSON_PRETTY_PRINT));
        Files::write($this->console . '/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        Files::write($this->console . '/dist/index.html', '<html>built console</html>');
        $this->writeHostModules();

        $this->runner    = new FakeProcessRunner();
        $this->publisher = new RecordingPublisher();
        $this->releases  = new FakeReleaseSource();
        $this->recorder  = new FakeInstallRecorder();
        $this->extension = new ExtensionRef('ext-uuid-1', 'extension_widgets', 'Widgets');

        $this->fakeComposerAndPnpm();
    }

    public function cleanup(): void
    {
        Files::delete($this->root);
    }

    public function writeHostModules(?string $ui = self::UI): void
    {
        Files::write($this->console . '/dist/host-modules.json', json_encode([
            'schema'   => 1,
            'console'  => '0.7.69',
            'versions' => ['@fleetbase/ember-ui' => $ui, '@fleetbase/ember-core' => self::CORE, 'ember-source' => '5.4.0'],
            'modules'  => $this->hostModules,
        ]));
    }

    public function config(): InstallerConfig
    {
        return InstallerConfig::fromArray(array_replace_recursive([
            'api_path'          => $this->api,
            'console_path'      => $this->console,
            'work_path'         => $this->root . '/work',
            'build_environment' => 'production',
        ], $this->configOverrides));
    }

    public function installer(): ExtensionInstaller
    {
        $config = $this->config();

        return new ExtensionInstaller($config, $this->releases, $this->runner, $this->publisher, $this->compat ?? new PhpEngineCompat(), $this->recorder, new ArtifactDownloader($config->requireChecksums));
    }

    public function manifest(): RuntimeEngineManifest
    {
        return new RuntimeEngineManifest($this->config()->runtimeManifestFile());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function job(string $action = 'install', array $overrides = []): array
    {
        return ['id' => 'job-' . $action, 'action' => $action, 'extension' => $this->extension->toArray(), 'version' => null, 'company' => 'company-1', ...$overrides];
    }

    /**
     * A release with the server package and, unless $dist is false, a prebuilt
     * engine dist archive.
     */
    public function release(string $version = '1.0.0', bool|string $dist = true, ?string $checksum = null, bool $composer = true, bool $current = true): Release
    {
        $artifacts = [];
        if ($dist !== false) {
            $archive     = is_string($dist) ? $dist : $this->buildDist($version);
            $artifacts[] = new Artifact(Artifact::DIST, $archive, $checksum ?? 'sha256-' . hash_file('sha256', $archive));
        }

        $release = new Release($this->extension, $version, $composer ? self::COMPOSER : null, $composer ? $version : null, self::ENGINE, $version, $artifacts);
        $this->releases->add($release, $current);

        return $release;
    }

    /**
     * Write a prebuilt release archive in the shape build-engine-release
     * produces, wrapped in a `package/` folder like an npm tarball.
     *
     * @param array<string, mixed>             $manifestOverrides
     * @param callable(string $dir): void|null $tamper            change files after hashing
     */
    public function buildDist(string $version = '1.0.0', array $manifestOverrides = [], ?callable $tamper = null, string $format = 'tgz', ?array $requires = null): string
    {
        $dir = $this->root . '/build-' . bin2hex(random_bytes(4));
        $rel = $dir . '/package';
        Files::write($rel . '/assets/engine.js', "define('" . self::ENGINE . "/engine', [], function () { return 'v{$version}'; });");
        Files::write($rel . '/assets/engine.css', '.widgets{}');
        Files::write($rel . '/routes.js', "define('" . self::ENGINE . "/routes', [], function () {});");
        Files::write($rel . '/host-app.js', '(function (define) {})(define);');
        Files::write($rel . '/extension.js', "define('" . self::ENGINE . "/extension', [], function () {});");
        Files::write($rel . '/translations.json', '[["en-us",{"widgets":{"title":"Widgets"}}]]');

        $files = [];
        foreach (Files::listFiles($rel) as $file) {
            $files[$file] = 'sha256-' . hash_file('sha256', $rel . '/' . $file);
        }

        $manifest = array_replace_recursive([
            'schema'       => 1,
            'name'         => self::ENGINE,
            'version'      => $version,
            'builtWith'    => ['console' => '0.7.69', 'environment' => 'production'],
            'builtAgainst' => ['@fleetbase/ember-ui' => self::UI, '@fleetbase/ember-core' => self::CORE, 'ember-source' => '5.4.0'],
            'runtime'      => [
                'mount'        => ['as' => 'widgets', 'path' => 'widgets', 'root' => false],
                'bundle'       => ['assets' => [['uri' => 'assets/engine.js', 'type' => 'js'], ['uri' => 'assets/engine.css', 'type' => 'css']], 'dependencies' => []],
                'config'       => ['modulePrefix' => self::ENGINE, 'environment' => 'production'],
                'routes'       => 'routes.js',
                'hostApp'      => 'host-app.js',
                'translations' => 'translations.json',
                'extension'    => 'extension.js',
            ],
            'requires' => ['modules' => $requires ?? ['@fleetbase/ember-ui/components/button', '@fleetbase/ember-core/services/fetch'], 'aliases' => [], 'unresolved' => []],
            'files'    => $files,
        ], $manifestOverrides);
        Files::write($rel . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($tamper) {
            $tamper($rel);
        }

        $archive = $this->root . '/dist-' . bin2hex(random_bytes(4)) . ($format === 'zip' ? '.zip' : '.tar');
        if ($format === 'zip') {
            $zip = new \ZipArchive();
            $zip->open($archive, \ZipArchive::CREATE);
            foreach (Files::listFiles($dir) as $file) {
                $zip->addFile($dir . '/' . $file, $file);
            }
            $zip->close();
        } else {
            $phar = new \PharData($archive);
            $phar->buildFromDirectory($dir);
            $phar->compress(\Phar::GZ);
            unset($phar);
            @unlink($archive);
            $archive .= '.gz';
        }
        Files::delete($dir);

        return $archive;
    }

    /**
     * composer require/remove and pnpm add/remove edit the manifests like the
     * real tools; install --frozen-lockfile and composer install are no-ops.
     */
    private function fakeComposerAndPnpm(): void
    {
        $edit = function (string $file, string $section, callable $change) {
            $json           = json_decode((string) file_get_contents($file), true);
            $json[$section] = $change($json[$section] ?? []);
            file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT));
        };

        $this->runner->on(['composer', 'require'], function (array $command) use ($edit) {
            [$name, $constraint] = array_pad(explode(':', $command[2], 2), 2, '*');
            $edit($this->api . '/composer.json', 'require', fn ($require) => [...$require, $name => $constraint]);
            file_put_contents($this->api . '/composer.lock', '{"packages":["fleetbase/core-api","' . $name . '"]}');

            return ['Running composer update ' . $name, 'Loading composer repositories with package information', 'Updating dependencies', 'Writing lock file', 'Generating optimized autoload files'];
        });
        $this->runner->on(['composer', 'remove'], function (array $command) use ($edit) {
            $edit($this->api . '/composer.json', 'require', fn ($require) => array_diff_key($require, [$command[2] => true]));
            file_put_contents($this->api . '/composer.lock', '{"packages":["fleetbase/core-api"]}');

            return ['Running composer update', '- Removing ' . $command[2]];
        });
        $this->runner->on(['pnpm', 'add'], function (array $command) use ($edit) {
            $at                  = strrpos($command[2], '@');
            [$name, $constraint] = $at > 0 ? [substr($command[2], 0, $at), substr($command[2], $at + 1)] : [$command[2], 'latest'];
            $edit($this->console . '/package.json', 'dependencies', fn ($deps) => [...$deps, $name => $constraint]);
            file_put_contents($this->console . '/pnpm-lock.yaml', "lockfileVersion: '9.0'\n# {$name}\n");

            return ['Packages: +1', 'Progress: resolved 900, reused 900', 'Done in 3s'];
        });
        $this->runner->on(['pnpm', 'remove'], function (array $command) use ($edit) {
            $edit($this->console . '/package.json', 'dependencies', fn ($deps) => array_diff_key($deps, [$command[2] => true]));

            return ['Packages: -1', 'Done in 2s'];
        });
        $this->runner->on(['pnpm', 'build'], function () {
            // ember build replaces dist/ wholesale.
            Files::delete($this->console . '/dist');
            Files::write($this->console . '/dist/index.html', '<html>rebuilt console</html>');
            Files::write($this->console . '/dist/host-modules.json', '{"modules":[]}');

            return ['- Building', 'cleaning up...', 'Built project successfully'];
        });
    }
}
