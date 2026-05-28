<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Installer\Lib;

/**
 * Storage for installer state
 */
class InstallerState
{
    /**
     * Checks the installer state
     *
     * @param string $state state to check agains
     * @return bool true if installer is in state $state
     */
    public static function check(string $state): bool
    {
        $path = self::getFilePath();
        if (!file_exists($path)) {
            return false;
        }

        return file_get_contents($path) === $state;
    }

    /**
     * Resets the installer state
     *
     * @return void
     */
    public static function reset(): void
    {
        $path = self::getFilePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Sets the installer state
     *
     * @param string $state the state
     * @return void
     */
    public static function set(string $state): void
    {
        file_put_contents(self::getFilePath(), $state);
    }

    /**
     * Gets path to state storage file
     *
     * The file is stored as file in writable directory. Cache isn't available
     * during the installation.
     *
     * @return string file path
     * @throws \RuntimeException
     */
    private static function getFilePath(): string
    {
        if (empty(TMP)) {
            throw new \RuntimeException('TMP directory not available.', 1560524787);
        }

        return TMP . 'installer.state';
    }
}
