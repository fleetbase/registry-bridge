<?php

namespace Fleetbase\RegistryBridge\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\RegistryBridge\Exceptions\InstallFailedException;
use Fleetbase\RegistryBridge\Support\Installer\OutputProgress;
use Fleetbase\RegistryBridge\Support\Utils;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\Process\Process;

class RegistryExtensionBundle extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasMetaAttributes;
    use HasApiModelBehavior;
    use Searchable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'registry_extension_bundles';

    /**
     * The database connection to use.
     *
     * Registry data belongs to the account and only exists in the live database
     * (sandbox migrations are disabled for this package), so it must never follow
     * the sandbox connection switch made by `Auth::setSandboxSession()`.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'bundle';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'company_uuid',
        'created_by_uuid',
        'extension_uuid',
        'bundle_uuid',
        'bundle_id',
        'bundle_number',
        'version',
        'meta',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'meta'                  => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'bundle_filename',
    ];

    /**
     * Relations that should be loaded with model.
     *
     * @var array
     */
    protected $with = [];

    /**
     * Relations that should not be loaded.
     *
     * @var array
     */
    protected $without = ['company', 'createdBy', 'extension'];

    /**
     * Searchable columns.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * The "booting" method of the model.
     *
     * This method is called on the model boot and sets up
     * event listeners, such as creating a unique bundle ID
     * when a new model instance is being created.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->bundle_id = self::generateUniqueBundleId();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(RegistryExtension::class, 'extension_uuid', 'uuid');
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(File::class, 'bundle_uuid', 'uuid');
    }

    /**
     * Get the bundle original filename.
     */
    public function getBundleFilenameAttribute(): ?string
    {
        if ($this->bundle instanceof File) {
            return $this->bundle->original_filename;
        }

        return data_get($this, 'bundle.original_filename');
    }

    /**
     * Generates a unique bundle ID.
     *
     * This static method constructs a unique bundle identifier (ID) by appending a random string to a fixed prefix.
     * The prefix used is 'EXTBNDL', and the total length of the generated ID is 14 characters, including the prefix.
     * The function ensures uniqueness by checking the generated ID against existing IDs in the database and
     * regenerating if a duplicate is found. The characters used for the random string are upper and lower case
     * alphabets and numerals. The final bundle ID is returned in upper case.
     *
     * @return string a unique, uppercase bundle ID
     */
    public static function generateUniqueBundleId(): string
    {
        do {
            $prefix          = 'EXTBNDL';
            $remainingLength = 14 - strlen($prefix);
            $characters      = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            $result          = '';

            for ($i = 0; $i < $remainingLength; $i++) {
                $result .= $characters[rand(0, strlen($characters) - 1)];
            }

            $bundleId = strtoupper($prefix . $result);
        } while (self::where('bundle_id', $bundleId)->exists());

        return $bundleId;
    }

    /**
     * Extracts specified file(s) from a zipped bundle and returns their contents.
     *
     * This method downloads a file indicated by the File model, unzips it, and looks for
     * specified filename(s) within the unzipped contents. The contents of each found file
     * are returned as a stdClass object, with each property corresponding to a filename.
     * If 'parse_json' option is true (default), file contents are decoded as JSON.
     * Temporary files are cleaned up after extraction.
     *
     * @param File         $bundle    the File model instance representing the zipped bundle
     * @param string|array $filenames a single filename or an array of filenames to extract from the bundle
     * @param array        $options   Additional options, e.g., ['parse_json' => false] to get raw file contents.
     *
     * @return \stdClass|null an object containing the contents of each extracted file, or null if files are not found
     */
    protected static function extractBundleFile(File $bundle, $filenames = 'extension.json', $options = []): ?\stdClass
    {
        $shouldParseJson = data_get($options, 'parse_json', true);
        $tempDir         = sys_get_temp_dir() . '/' . str_replace(['.', ','], '_', uniqid('fleetbase_archive_', true));
        mkdir($tempDir);

        // Download the file to a local temporary directory
        $tempFilePath = $tempDir . '/' . basename($bundle->path);
        $contents     = Storage::disk($bundle->disk)->get($bundle->path);
        file_put_contents($tempFilePath, $contents);

        // Extract file paths
        $extractedFilePaths = static::_extractAndFindFile($tempFilePath, $tempDir, $filenames, $bundle->getExtension());
        $result             = new \stdClass();
        foreach ($extractedFilePaths as $filename => $path) {
            if (file_exists($path)) {
                $fileContents = file_get_contents($path);
                if ($shouldParseJson) {
                    $result->$filename = json_decode($fileContents);
                } else {
                    $result->$filename = $fileContents;
                }
            }
        }

        // Cleanup: Delete the temporary directory
        try {
            array_map('unlink', glob("$tempDir/*.*"));
        } catch (\Throwable $e) {
            // Probably a directory ...
        }
        Utils::deleteDirectory($tempDir);

        return $result;
    }

    /**
     * Extracts specified files from an uploaded bundle file and optionally parses their contents.
     *
     * This method handles the extraction of files from an uploaded archive (ZIP or TAR.GZ) and returns their contents.
     *
     * @param UploadedFile $bundle    the uploaded bundle file to extract
     * @param string|array $filenames The filename or array of filenames to extract from the bundle (default: 'extension.json').
     * @param array        $options   additional options:
     *                                - 'parse_json' (bool): Whether to parse the file contents as JSON (default: true)
     *
     * @return \stdClass|null an object containing the extracted file contents, or null if extraction fails
     *
     * @throws \Exception if extraction of the bundle fails
     */
    public static function extractUploadedBundleFile(UploadedFile $bundle, $filenames = 'extension.json', $options = []): ?\stdClass
    {
        $shouldParseJson = data_get($options, 'parse_json', true);
        $tempDir         = sys_get_temp_dir() . '/' . str_replace(['.', ','], '_', uniqid('fleetbase_archive_', true));
        mkdir($tempDir);

        // Download the file to a local temporary directory
        $tempFilePath = $bundle->getPathname();

        // Get the archive extension
        $extension = $bundle->guessExtension();
        if ($extension === 'gz') {
            $extension = 'tar.gz';
        }

        // Extract file paths
        $extractedFilePaths = static::_extractAndFindFile($tempFilePath, $tempDir, $filenames, $extension);
        $result             = new \stdClass();
        foreach ($extractedFilePaths as $filename => $path) {
            if (file_exists($path)) {
                $fileContents = file_get_contents($path);
                if ($shouldParseJson) {
                    $result->$filename = json_decode($fileContents);
                } else {
                    $result->$filename = $fileContents;
                }
            }
        }

        // Cleanup: Delete the temporary directory
        try {
            array_map('unlink', glob("$tempDir/*.*"));
        } catch (\Throwable $e) {
            // Probably a directory ...
        }
        Utils::deleteDirectory($tempDir);

        return $result;
    }

    /**
     * Extracts target files from an archive and finds their paths.
     *
     * This method handles both ZIP and TAR.GZ archives. It extracts the archive to a temporary directory
     * and searches for the specified target files within the extracted contents.
     *
     * @param string       $archiveFilePath the full path to the archive file to extract
     * @param string       $tempDir         the temporary directory where the archive will be extracted
     * @param string|array $targetFiles     the filename or array of filenames to search for within the extracted contents
     * @param string       $extension       the expected extension of the archive file (default: 'zip')
     *
     * @return array an associative array where keys are target filenames and values are their full paths within the extracted contents
     *
     * @throws \Exception if the archive format is unsupported or extraction fails
     */
    private static function _extractAndFindFile($archiveFilePath, $tempDir, $targetFiles, $extension = 'zip'): array
    {
        $paths = [];

        // Ensure $tempDir exists
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        // Get the base name of the archive file with extension
        $archiveFileName = basename($archiveFilePath);

        // Determine the extension of the archive file
        $archiveExtension = pathinfo($archiveFileName, PATHINFO_EXTENSION);
        if (empty($archiveExtension)) {
            // Try to determine the extension from the MIME type
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($archiveFilePath);

            if ($mimeType === 'application/zip') {
                $archiveExtension = 'zip';
            } elseif ($mimeType === 'application/gzip' || $mimeType === 'application/x-gzip') {
                $archiveExtension = 'gz';
            } else {
                $archiveExtension = $extension; // Default to 'zip' if not detected
            }
        }

        // Create a temporary file in $tempDir with the correct extension
        $tempArchivePath = $tempDir . '/' . uniqid('archive_', false) . '.' . $archiveExtension;

        // Copy the archive file to the temporary file
        copy($archiveFilePath, $tempArchivePath);

        $zip = new \ZipArchive();

        if ($zip->open($tempArchivePath) === true) {
            // If it's a ZIP file, extract it
            $zip->extractTo($tempDir);
            $zip->close();
            // Remove the temp archive file
            unlink($tempArchivePath);
        } else {
            // Attempt to handle as tar.gz or tar using system 'tar' command
            try {
                // Determine the appropriate flags for tar extraction
                $flags = '';
                if (in_array($archiveExtension, ['gz', 'tgz', 'tar.gz'])) {
                    // For .tar.gz or .tgz files
                    $flags = '-xzf';
                } elseif ($archiveExtension === 'tar') {
                    // For .tar files
                    $flags = '-xf';
                } else {
                    // Unsupported archive format
                    unlink($tempArchivePath);
                    throw new \Exception('Unsupported archive format.');
                }

                // Build the command safely using escapeshellarg
                $command = sprintf('tar %s %s -C %s', $flags, escapeshellarg($tempArchivePath), escapeshellarg($tempDir));

                // Execute the command
                $output    = [];
                $returnVar = 0;
                exec($command, $output, $returnVar);

                // Check if the command was successful
                if ($returnVar !== 0) {
                    throw new \Exception('Extraction failed with code ' . $returnVar . ': ' . implode("\n", $output));
                }

                // Clean up temporary archive file
                unlink($tempArchivePath);
            } catch (\Exception $e) {
                // Handle extraction errors
                // Clean up temporary files
                if (file_exists($tempArchivePath)) {
                    unlink($tempArchivePath);
                }
                // Throw the exception
                throw new \Exception('Failed to extract archive: ' . $e->getMessage());
            }
        }

        // Now search for the target files in the extracted contents
        foreach ((array) $targetFiles as $targetFile) {
            // Direct check in the tempDir
            $directPath = $tempDir . '/' . $targetFile;
            if (file_exists($directPath)) {
                $paths[$targetFile] = $directPath;
                continue;
            }

            // Check in the first subdirectory
            $files = scandir($tempDir);
            foreach ($files as $file) {
                $invalidDirectories = ['__MACOSX', '.', '..', 'DS_Store', '.DS_Store', '.idea', '.vscode'];
                if (!in_array($file, $invalidDirectories) && is_dir($tempDir . '/' . $file)) {
                    $nestedPath = $tempDir . '/' . $file . '/' . $targetFile;
                    if (file_exists($nestedPath)) {
                        $paths[$targetFile] = $nestedPath;
                        break;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * Extracts multiple configuration files from the bundle.
     *
     * This method leverages the extractBundleFile function to extract an array
     * of specified configuration files ('extension.json', 'composer.json', 'package.json')
     * from the bundle. It returns an object containing the contents of each file,
     * where each property name corresponds to a filename. If a specified file is not found
     * in the bundle, its corresponding property will be absent in the returned object.
     * The method is useful for retrieving multiple configuration files in a single operation.
     *
     * @return \stdClass|null An object containing the contents of each extracted file.
     *                        Properties of the object correspond to the filenames.
     *                        Returns null if the bundle property is not an instance of File.
     */
    public static function extractBundleData(File $bundleFile): ?\stdClass
    {
        return static::extractBundleFile($bundleFile, ['extension.json', 'composer.json', 'package.json']);
    }

    /**
     * Extracts multiple configuration files from the bundle.
     *
     * This method leverages the extractBundleFile function to extract an array
     * of specified configuration files ('extension.json', 'composer.json', 'package.json')
     * from the bundle. It returns an object containing the contents of each file,
     * where each property name corresponds to a filename. If a specified file is not found
     * in the bundle, its corresponding property will be absent in the returned object.
     * The method is useful for retrieving multiple configuration files in a single operation.
     *
     * @return \stdClass|null An object containing the contents of each extracted file.
     *                        Properties of the object correspond to the filenames.
     *                        Returns null if the bundle property is not an instance of File.
     */
    public function extractExtensionData()
    {
        if ($this->bundle instanceof File) {
            return static::extractBundleData($this->bundle);
        }
    }

    /**
     * Extracts 'extension.json' from the bundle file.
     *
     * This method is a specific implementation of extractBundleFile for extracting
     * 'extension.json'. It checks if the 'bundle' property is an instance of File
     * and invokes the extraction process.
     *
     * @return \stdClass|null The decoded JSON object from 'extension.json', or null if not found.
     */
    public function extractExtensionJson(): ?\stdClass
    {
        $filename = 'extension.json';
        if ($this->bundle instanceof File) {
            $data = static::extractBundleFile($this->bundle);

            return $data[$filename];
        }
    }

    /**
     * Extracts 'composer.json' from the bundle file.
     *
     * This method is a specific implementation of extractBundleFile for extracting
     * 'composer.json'. It checks if the 'bundle' property is an instance of File
     * and invokes the extraction process.
     *
     * @return \stdClass|null The decoded JSON object from 'composer.json', or null if not found.
     */
    public function extractComposerJson(): ?\stdClass
    {
        $filename = 'composer.json';
        if ($this->bundle instanceof File) {
            $data = static::extractBundleFile($this->bundle, $filename);

            return $data[$filename];
        }
    }

    /**
     * Extracts 'package.json' from the bundle file.
     *
     * This method is a specific implementation of extractBundleFile for extracting
     * 'package.json'. It checks if the 'bundle' property is an instance of File
     * and invokes the extraction process.
     *
     * @return \stdClass|null The decoded JSON object from 'package.json', or null if not found.
     */
    public function extractPackageJson(): ?\stdClass
    {
        $filename = 'package.json';
        if ($this->bundle instanceof File) {
            $data = static::extractBundleFile($this->bundle, $filename);

            return $data[$filename];
        }
    }

    /**
     * Installs a specific server package using Composer based on provided metadata.
     *
     * This method manages the installation of a server-side package specified in the composer.json metadata.
     * It executes a Composer command to install the package, monitors the output for progress, and broadcasts
     * these updates in real-time to a WebSocket channel.
     *
     * @throws InstallFailedException if the Composer package installation fails, with a user-friendly message
     */
    public function installComposerPackage(): void
    {
        if (!is_array($this->meta) || !isset($this->meta['composer.json'])) {
            return;
        }

        $composerJson = $this->meta['composer.json'];
        if (!$composerJson) {
            return;
        }

        // Prepare for install
        $output          = '';
        $installChannel  = implode('.', ['install', $this->company_uuid, $this->extension_uuid]);
        $packageName     = data_get($composerJson, 'name');
        $version         = data_get($composerJson, 'version');
        $composerCommand = [
            'composer',
            'require',
            $packageName . ($version === 'latest' ? '' : ':' . $version),
        ];

        // Create process
        $process = new Process($composerCommand);
        $process->setWorkingDirectory('/fleetbase/api');
        $process->setTimeout(3600 * 2);

        // Run process
        $process->run(function ($type, $buffer) use (&$output, $installChannel) {
            $output .= $buffer;
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $progress = static::composerInstallOutputProgress($line);
                if ($progress > 0) {
                    SocketClusterService::publish($installChannel, [
                        'process'  => 'install',
                        'step'     => 'server.install',
                        'progress' => $progress,
                    ]);
                }
            }
        });

        if (!$process->isSuccessful()) {
            $friendlyMessage = static::composerOutputFriendly($output);
            throw new InstallFailedException($friendlyMessage);
        }
    }

    /**
     * Uninstalls a specific server package using Composer based on provided metadata.
     *
     * This function handles the uninstallation of a server-side package as defined in the composer.json metadata.
     * It runs a Composer remove command, processes the output to track uninstallation progress, and publishes
     * these updates through a WebSocket channel.
     *
     * @throws InstallFailedException if the Composer package uninstallation fails, providing a user-friendly message
     */
    public function uninstallComposerPackage(): void
    {
        if (!is_array($this->meta) || !isset($this->meta['composer.json'])) {
            return;
        }

        $composerJson = $this->meta['composer.json'];
        if (!$composerJson) {
            return;
        }

        // Prepare for uninstall
        $output           = '';
        $uninstallChannel = implode('.', ['uninstall', $this->company_uuid, $this->extension_uuid]);
        $packageName      = data_get($composerJson, 'name');
        $composerCommand  = [
            'composer',
            'remove',
            $packageName,
        ];

        // Create process
        $process = new Process($composerCommand);
        $process->setWorkingDirectory('/fleetbase/api');
        $process->setTimeout(3600 * 2);

        // Run process
        $process->run(function ($type, $buffer) use (&$output, $uninstallChannel) {
            $output .= $buffer;
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $progress = static::composerUninstallOutputProgress($line);
                if ($progress > 0) {
                    SocketClusterService::publish($uninstallChannel, [
                        'process'  => 'uninstall',
                        'step'     => 'server.uninstall',
                        'progress' => $progress,
                    ]);
                }
            }
        });

        if (!$process->isSuccessful()) {
            $friendlyMessage = static::composerOutputFriendly($output);
            throw new InstallFailedException($friendlyMessage);
        }
    }

    /**
     * Installs a specific engine package defined in the metadata using PNPM.
     *
     * This method is responsible for installing an engine package based on the package metadata provided.
     * It creates and runs a PNPM installation process, monitors its output for progress, and sends real-time
     * updates via a WebSocket channel.
     *
     * @throws \Exception if the engine installation process fails
     */
    public function installEnginePackage(): void
    {
        if (!is_array($this->meta) || !isset($this->meta['package.json'])) {
            return;
        }

        $packageJson = $this->meta['package.json'];
        if (!$packageJson) {
            return;
        }

        // Prepare for install
        $output          = '';
        $installChannel  = implode('.', ['install', $this->company_uuid, $this->extension_uuid]);
        $packageName     = data_get($packageJson, 'name');
        $version         = data_get($packageJson, 'version');
        $installCommand  = [
            'pnpm',
            'add',
            $packageName . ($version === 'latest' ? '' : '@' . $version),
        ];

        // Create process
        $process = new Process($installCommand);
        $process->setWorkingDirectory(config('fleetbase.console.path'));
        $process->setTimeout(3600 * 2);

        // Run process
        $process->run(function ($type, $buffer) use (&$output, $installChannel) {
            $output .= $buffer;
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $progress = static::pnpmInstallOutputProgress($line);
                if ($progress > 0) {
                    SocketClusterService::publish($installChannel, [
                        'process'  => 'install',
                        'step'     => 'engine.install',
                        'progress' => $progress,
                    ]);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \Exception('Engine install failed!');
        }
    }

    /**
     * Uninstalls a specific engine package defined in the metadata using PNPM.
     *
     * This function initiates the uninstallation of an engine package using PNPM, based on the metadata provided.
     * It captures and interprets the output of the uninstall command to provide real-time progress updates through
     * a WebSocket channel.
     *
     * @throws \Exception if the engine uninstallation process fails
     */
    public function uninstallEnginePackage(): void
    {
        if (!is_array($this->meta) || !isset($this->meta['package.json'])) {
            return;
        }

        $packageJson = $this->meta['package.json'];
        if (!$packageJson) {
            return;
        }

        // Prepare for uninstall
        $output           = '';
        $uninstallChannel = implode('.', ['uninstall', $this->company_uuid, $this->extension_uuid]);
        $packageName      = data_get($packageJson, 'name');
        $installCommand   = [
            'pnpm',
            'remove',
            $packageName,
        ];

        // Create process
        $process = new Process($installCommand);
        $process->setWorkingDirectory(config('fleetbase.console.path'));
        $process->setTimeout(3600 * 2);

        // Run process
        $process->run(function ($type, $buffer) use (&$output, $uninstallChannel) {
            $output .= $buffer;
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $progress = static::pnpmUninstallOutputProgress($line);
                if ($progress > 0) {
                    SocketClusterService::publish($uninstallChannel, [
                        'process'  => 'uninstall',
                        'step'     => 'engine.uninstall',
                        'progress' => $progress,
                    ]);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \Exception('Engine uninstall failed!');
        }
    }

    /**
     * Initiates and manages the build process of the Fleetbase console using PNPM.
     *
     * This method prepares and executes the build process for the Fleetbase console. It monitors the build
     * output to provide real-time updates via a WebSocket channel. It captures and interprets the output to
     * estimate the progress, which is then published to a designated channel for frontend display.
     *
     * @throws \Exception if the build process fails
     */
    public function buildConsole()
    {
        // Prepare to build/rebuild console
        $output         = '';
        $buildChannel   = implode('.', ['install', $this->company_uuid, $this->extension_uuid]);
        $buildCommand   = [
            'pnpm',
            'build',
            '--environment',
            config('app.env'),
        ];

        // Create process
        $process = new Process($buildCommand);
        $process->setWorkingDirectory(config('fleetbase.console.path'));
        $process->setTimeout(3600 * 2);

        // Run process
        $process->run(function ($type, $buffer) use (&$output, $buildChannel) {
            $output .= $buffer;
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $progress = static::pnpmBuildOutputProgress($line);
                if ($progress > 0) {
                    SocketClusterService::publish($buildChannel, [
                        'process'  => 'build',
                        'step'     => 'console.build',
                        'progress' => $progress,
                    ]);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \Exception('Console build failed!');
        }
    }

    /**
     * Simulates the installation progress of an extension by publishing progress updates.
     *
     * This method publishes progress updates to a SocketCluster channel for each step in the installation process.
     * It can be used to provide real-time feedback to clients about the installation status.
     */
    public function runInstallerProgress(): void
    {
        $channel   = implode('.', ['install', $this->company_uuid, $this->extension_uuid]);
        $steps     = ['api.install', 'engine.install', 'console.build'];

        foreach ($steps as $step) {
            $run = range(1, 100);
            foreach ($run as $progress) {
                SocketClusterService::publish($channel, [
                    'process'  => 'install',
                    'step'     => $step,
                    'progress' => $progress,
                ]);

                // minimal latency
                usleep(50 * rand(1, 3));
            }
        }
    }

    /**
     * Simulates the uninstallation progress of an extension by publishing progress updates.
     *
     * This method publishes progress updates to a SocketCluster channel for each step in the uninstallation process.
     * It can be used to provide real-time feedback to clients about the uninstallation status.
     */
    public function runUninstallerProgress(): void
    {
        $channel   = implode('.', ['uninstall', $this->company_uuid, $this->extension_uuid]);
        $steps     = ['api.uninstall', 'engine.uninstall', 'console.build'];

        foreach ($steps as $step) {
            $run = range(1, 100);
            foreach ($run as $progress) {
                SocketClusterService::publish($channel, [
                    'process'  => 'uninstall',
                    'step'     => $step,
                    'progress' => $progress,
                ]);

                // minimal latency
                usleep(50 * rand(1, 3));
            }
        }
    }

    /**
     * Parses Composer output to provide a user-friendly message.
     *
     * This method checks the Composer output for specific keywords or phrases
     * and returns a simplified, more understandable message suitable for end-users.
     *
     * @param string $output the raw output from Composer
     *
     * @return string a user-friendly interpretation of the output
     */
    public static function composerOutputFriendly($output): string
    {
        return OutputProgress::composerOutputFriendly((string) $output);
    }

    /**
     * Estimates the progress of a Composer installation process.
     *
     * This method interprets the Composer output to estimate the progress of the installation.
     * It assigns a progress percentage based on identified keywords or phrases in the output.
     *
     * @param string $output the raw output from Composer
     *
     * @return int an estimated progress percentage
     */
    public static function composerInstallOutputProgress($output): int
    {
        return OutputProgress::composerInstallOutputProgress((string) $output);
    }

    /**
     * Parses the output of the composer uninstall process to determine progress.
     *
     * This function analyzes the output of the `composer remove` command and returns a progress percentage
     * based on the stage of the process. It helps in providing real-time progress updates during the
     * uninstallation of a Composer package.
     *
     * @param string $output the output from the composer uninstall process
     *
     * @return int the progress percentage
     */
    public static function composerUninstallOutputProgress($output): int
    {
        return OutputProgress::composerUninstallOutputProgress((string) $output);
    }

    /**
     * Estimates the progress of a PNPM installation process.
     *
     * This method interprets the PNPM output to estimate the progress of the installation based on specific keywords and phrases found in the output. It assigns a progress percentage based on identified phases of the install process, such as resolving dependencies and writing lock files.
     *
     * @param string $output the raw output from the PNPM install command
     *
     * @return int An estimated progress percentage. This is an integer between 0 and 100 where 0 means just started, and 100 means complete.
     */
    public static function pnpmInstallOutputProgress($output): int
    {
        return OutputProgress::pnpmInstallOutputProgress((string) $output);
    }

    /**
     * Estimates the progress of a PNPM uninstallation process.
     *
     * This method analyzes the output from the PNPM uninstall command to estimate the progress of the package removal. It uses specific indicators within the output to assign a progress percentage, such as the stages of resolving dependencies, removing packages, and cleaning up.
     *
     * @param string $output the raw output from the PNPM uninstall command
     *
     * @return int An estimated progress percentage. This value is an integer between 0 and 100, where 0 indicates the beginning of the uninstall process, and 100 indicates completion.
     */
    public static function pnpmUninstallOutputProgress($output): int
    {
        return OutputProgress::pnpmUninstallOutputProgress((string) $output);
    }

    /**
     * Estimates the progress of a PNPM Ember build process.
     *
     * This function interprets the output from the 'pnpm build' command, specifically tailored for an Ember project build,
     * to estimate the build's progress. The function identifies specific phases of the build process and assigns a
     * progress percentage based on these phases.
     *
     * @param string $output the raw output from the PNPM build command
     *
     * @return int An estimated progress percentage. This is an integer between 0 and 100, where 0 means just started,
     *             and 100 indicates the build is complete.
     */
    public static function pnpmBuildOutputProgress($output): int
    {
        return OutputProgress::pnpmBuildOutputProgress((string) $output);
    }

    /**
     * Calculates the next bundle number for a given extension.
     *
     * This method counts the existing bundles associated with the extension and returns the next sequential number.
     *
     * @param string|RegistryExtension $extension the UUID of the extension or a RegistryExtension instance
     *
     * @return int the next bundle number for the extension
     */
    public static function getNextBundleNumber(string|RegistryExtension $extension): int
    {
        $numberOfBundles = RegistryExtensionBundle::whereHas('extension', function ($query) use ($extension) {
            $query->where('uuid', Str::isUuid($extension) ? $extension : $extension->uuid);
        })->count();

        return ($numberOfBundles ?? 0) + 1;
    }

    /**
     * Retrieves the next bundle number for the current extension instance.
     *
     * @return int the next bundle number
     */
    public function nextBundleNumber()
    {
        return static::getNextBundleNumber($this->extension_uuid);
    }
}
