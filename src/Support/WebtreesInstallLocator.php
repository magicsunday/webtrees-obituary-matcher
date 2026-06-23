<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function is_file;
use function is_string;
use function realpath;

/**
 * Layout-independent locator for the running webtrees install root, given the module's own root
 * directory. The CLI entry points and the headless bootstrap previously each hardcoded the
 * source-checkout layout (the module as a sibling of `fisharebest/webtrees` inside a shared `vendor/`),
 * so a `modules_v4` drop-in install never resolved. This locator centralises that path arithmetic and
 * probes the realistic install layouts in order, returning the first whose `data/config.ini.php` exists.
 *
 * It is PURE path logic with NO dependency on the webtrees framework, so it lives in the `Support` layer
 * and is unit-testable against a synthetic temp directory tree: the module root is injected rather than
 * derived from `__DIR__`, so a test can point it at any layout.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class WebtreesInstallLocator
{
    /**
     * The webtrees config file, resolved relative to an install root.
     */
    private const string CONFIG_RELATIVE_PATH = '/data/config.ini.php';

    /**
     * @param string $moduleRootDir The module's own root directory (the package root that contains
     *                              `src/`, `tools/`, …; e.g. `dirname(__DIR__, 2)` from `src/Support`,
     *                              or `dirname(__DIR__)` from `tools/`). The candidate install roots are
     *                              resolved relative to it.
     */
    public function __construct(
        private string $moduleRootDir,
    ) {
    }

    /**
     * Locates the webtrees install root (the directory CONTAINING `data/config.ini.php`) by probing the
     * realistic install layouts in order and returning the first match (canonicalised via `realpath`), or
     * null when none of them carries a config.
     *
     * The candidate install roots, relative to the module root, are:
     *   - `<moduleRoot>/../../fisharebest/webtrees` — the source-checkout / Composer-into-host-vendor
     *     layout: the module installs at `vendor/magicsunday/<m>`, so webtrees is the sibling
     *     `vendor/fisharebest/webtrees`.
     *   - `<moduleRoot>/../..` — the `modules_v4` drop-in layout: the module installs at
     *     `<webtrees>/modules_v4/<m>`, so the webtrees install root is two directories up.
     *
     * @return string|null
     */
    public function installRoot(): ?string
    {
        $candidates = [
            $this->moduleRootDir . '/../../fisharebest/webtrees',
            $this->moduleRootDir . '/../..',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate . self::CONFIG_RELATIVE_PATH)) {
                $resolved = realpath($candidate);

                if (is_string($resolved)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * Returns the absolute path to the located install's `data/config.ini.php`, or null when no install
     * root could be located.
     *
     * @return string|null
     */
    public function configFile(): ?string
    {
        $installRoot = $this->installRoot();

        return $installRoot === null ? null : $installRoot . self::CONFIG_RELATIVE_PATH;
    }

    /**
     * Returns the absolute path to the located install's `data` directory, or null when no install root
     * could be located.
     *
     * @return string|null
     */
    public function dataDir(): ?string
    {
        $installRoot = $this->installRoot();

        return $installRoot === null ? null : $installRoot . '/data';
    }

    /**
     * Returns the absolute path to the located install's default obituary-matcher queue root
     * (`data/obituary-matcher/queue`), or null when no install root could be located.
     *
     * @return string|null
     */
    public function defaultQueueRoot(): ?string
    {
        $dataDir = $this->dataDir();

        return $dataDir === null ? null : $dataDir . '/obituary-matcher/queue';
    }
}
