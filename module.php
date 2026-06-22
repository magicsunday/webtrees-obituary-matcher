<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;

// Register the module's own namespace for drop-in use. This is harmless and idempotent when the
// Composer autoloader already maps the namespace (the VendorModuleService install path), and it lets
// the module load as a stand-alone modules_v4 drop-in where no project-level autoload is active.
$loader = new ClassLoader();
$loader->addPsr4('MagicSunday\\ObituaryMatcher\\', __DIR__ . '/src');
$loader->register();

// Create and return the module instance.
return new ObituaryMatcherModule();
