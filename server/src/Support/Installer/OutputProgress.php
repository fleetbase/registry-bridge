<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * Turns Composer and pnpm output lines into progress percentages and friendly
 * messages for the install socket events.
 */
class OutputProgress
{
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
    public static function composerOutputFriendly(string $output): string
    {
        // Check for successful install/update
        if (strpos($output, 'Generating optimized autoload files') !== false) {
            return 'Installation successful. Packages have been updated.';
        }

        // Check for 'Nothing to install, update or remove'
        if (strpos($output, 'Nothing to install, update or remove') !== false) {
            return 'No changes made. Everything is already up-to-date.';
        }

        // Check for 'Package is abandoned'
        if (preg_match_all('/Package (.+) is abandoned/', $output, $matches)) {
            $abandonedPackages = implode(', ', $matches[1]);

            return "Warning: The following packages are abandoned and should be replaced: $abandonedPackages.";
        }

        // Check for dependency issues
        if (preg_match('/Problem (\d+)/', $output, $matches)) {
            return 'Unable to install due to dependency compatibility issues.';
        }

        // If no known patterns are found, return a generic message or the original output
        return 'An error occurred during installation. Please check the log for details.';
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
    public static function composerInstallOutputProgress(string $output): int
    {
        // Trim the output to remove unnecessary whitespace
        $output = trim($output);

        // Initial phase, updating composer.json and resolving dependencies
        if (strpos($output, 'Running composer update') !== false) {
            return 10;
        }
        // Dependencies are being updated
        elseif (strpos($output, 'Loading composer repositories with package information') !== false) {
            return 20;
        }
        // Dependencies updating step
        elseif (strpos($output, 'Updating dependencies') !== false) {
            return 30;
        }
        // Lock file is being written
        elseif (strpos($output, 'Lock file operations') !== false) {
            return 40;
        }
        // Lock file writing in progress
        elseif (strpos($output, 'Writing lock file') !== false) {
            return 50;
        }
        // Installing dependencies
        elseif (strpos($output, 'Installing dependencies from lock file') !== false) {
            return 60;
        }
        // Package operations, installation begins
        elseif (strpos($output, 'Package operations:') !== false) {
            return 70;
        }
        // Downloading a specific package
        elseif (strpos($output, '- Downloading') !== false) {
            return 75;
        }
        // Extracting archive for a package
        elseif (strpos($output, '- Installing') !== false) {
            return 80;
        }
        // Autoload files are generated, nearing completion
        elseif (strpos($output, 'Generating optimized autoload files') !== false) {
            return 90;
        }
        // Final steps, package discovery and publishing assets
        elseif (strpos($output, 'postAutoloadDump') !== false
                || strpos($output, '@php artisan package:discover --ansi') !== false
                || strpos($output, '@php artisan vendor:publish') !== false) {
            return 95;
        }
        // Completion messages
        elseif (strpos($output, 'No security vulnerability advisories found') !== false) {
            return 100;
        }

        // Default progress if no known phrases are matched
        return 0;
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
    public static function composerUninstallOutputProgress(string $output): int
    {
        // Trim the output to remove unnecessary whitespace
        $output = trim($output);

        // Initial phase, updating composer.json and resolving dependencies
        if (strpos($output, 'Running composer update') !== false) {
            return 10;
        }
        // Loading repositories
        elseif (strpos($output, 'Loading composer repositories with package information') !== false) {
            return 20;
        }
        // Dependencies are being updated
        elseif (strpos($output, 'Updating dependencies') !== false) {
            return 30;
        }
        // Lock file operations are defined
        elseif (strpos($output, 'Lock file operations') !== false) {
            return 40;
        }
        // Lock file writing in progress
        elseif (strpos($output, 'Writing lock file') !== false) {
            return 50;
        }
        // Installing dependencies from lock file
        elseif (strpos($output, 'Installing dependencies from lock file') !== false) {
            return 60;
        }
        // Package operations, removal begins
        elseif (strpos($output, 'Package operations:') !== false) {
            return 70;
        }
        // Removing a specific package
        elseif (strpos($output, '- Removing') !== false) {
            return 80;
        }
        // Generating autoload files, nearing completion
        elseif (strpos($output, 'Generating optimized autoload files') !== false) {
            return 90;
        }
        // Final steps, package discovery and publishing assets
        elseif (strpos($output, 'postAutoloadDump') !== false
                || strpos($output, '@php artisan package:discover --ansi') !== false
                || strpos($output, '@php artisan vendor:publish') !== false) {
            return 95;
        }
        // Completion messages
        elseif (strpos($output, 'No security vulnerability advisories found') !== false) {
            return 100;
        }

        // Default progress if no known phrases are matched
        return 0;
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
    public static function pnpmInstallOutputProgress(string $output): int
    {
        $output = trim($output);

        // Check if the output indicates starting phase
        if (strpos($output, 'Packages: +1') !== false) {
            return 10;
        }
        // Check for resolved packages progress
        elseif (preg_match('/Progress: resolved (\d+),/', $output, $matches)) {
            $resolved = (int) $matches[1];
            if ($resolved < 500) {
                return 20;
            } elseif ($resolved < 1000) {
                return 40;
            } elseif ($resolved < 1500) {
                return 60;
            } else {
                return 80;
            }
        }
        // Check for final steps
        elseif (strpos($output, 'dependencies:') !== false) {
            return 90;
        }
        // Completion message
        elseif (strpos($output, 'Done in') !== false) {
            return 100;
        }

        return 0;
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
    public static function pnpmUninstallOutputProgress(string $output): int
    {
        $output = trim($output);

        // Check for the uninstall initiation
        if (strpos($output, 'Packages: -1') !== false) {
            return 10;
        }
        // Check for resolved packages progress
        elseif (preg_match('/Progress: resolved (\d+),/', $output, $matches)) {
            $resolved = (int) $matches[1];
            if ($resolved < 500) {
                return 20;
            } elseif ($resolved < 1000) {
                return 40;
            } elseif ($resolved < 1500) {
                return 60;
            } else {
                return 80;
            }
        }
        // Check for final steps
        elseif (strpos($output, 'dependencies:') !== false) {
            return 90;
        }
        // Completion message
        elseif (strpos($output, 'Done in') !== false) {
            return 100;
        }

        return 0;
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
    public static function pnpmBuildOutputProgress(string $output): int
    {
        $output = trim($output);

        // Prebuild and setup phase
        if (strpos($output, 'node prebuild.js') !== false) {
            return 10;
        }
        // Initial building phase
        elseif (strpos($output, '- Building') !== false) {
            return 20;
        }
        // Middle build process, handling various transformations and optimizations
        elseif (strpos($output, 'postcss-is-pseudo-class') !== false) {
            return 50;
        }
        // Cleanup phase before completion
        elseif (strpos($output, 'cleaning up...') !== false) {
            return 80;
        }
        // Build completion message
        elseif (strpos($output, 'Built project successfully') !== false) {
            return 100;
        }

        // Default progress if no known phrases are matched
        return 0;
    }
}
