<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Shared pre-autoload bootstrap for the developer CLI entry points (`tools/drain.php`,
 * `tools/seed-match-store.php`). It locates and requires the Composer autoloader, trying the realistic
 * install layouts in order and requiring the first that exists.
 *
 * This file is DELIBERATELY plain procedural — NOT a class — because it runs BEFORE any Composer
 * autoloader is registered, so it cannot itself be autoloaded; it must resolve the autoloader by hand.
 * It is consequently not unit-tested (irreducible pre-autoload glue); the `tools/` directory is covered
 * by `phpstan-glue.neon`.
 *
 * The candidate order keeps the source-checkout dev tooling working unchanged: the checkout's
 * `.build/vendor/autoload.php` MUST stay first so the current developer setup is byte-for-byte
 * unaffected; the remaining candidates only ever match in a non-checkout install.
 *
 *   (a) `<module>/.build/vendor/autoload.php`  — the source-checkout dev tooling (current setup).
 *   (b) `<module>/vendor/autoload.php`         — a module-local Composer install.
 *   (c) `<module>/../../../autoload.php`        — the module in a host `vendor/` tree → `vendor/autoload.php`
 *                                                (module at `vendor/magicsunday/<m>`, autoloader at `vendor/autoload.php`).
 *   (d) `<module>/../../../vendor/autoload.php` — a `modules_v4` drop-in → the webtrees root's `vendor/autoload.php`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

$autoloadCandidates = [
    __DIR__ . '/../.build/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadCandidate) {
    // is_readable (not is_file): an existing-but-unreadable autoloader would fatal on `require`, so
    // guard on readability — the predicate that actually matches what `require` needs.
    if (is_readable($autoloadCandidate)) {
        require $autoloadCandidate;

        return;
    }
}

fwrite(STDERR, 'Could not locate the Composer autoloader; run `composer install` for this module.' . PHP_EOL);

exit(1);
