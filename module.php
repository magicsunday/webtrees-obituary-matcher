<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;

/**
 * Minimal custom-module shell registering the Obituary Matcher with webtrees; the matching
 * engine lives in the package's `src/` namespace and is wired in by later phases.
 */
return new class extends AbstractModule implements ModuleCustomInterface {
    use ModuleCustomTrait;

    /**
     * How should this module be identified in the control panel, etc.?
     *
     * @return string The module title shown in the control panel.
     */
    public function title(): string
    {
        return 'Obituary Matcher';
    }

    /**
     * A sentence describing what this module does.
     *
     * @return string The one-sentence module description.
     */
    public function description(): string
    {
        return 'Match individuals against public death notices to suggest missing death dates.';
    }

    /**
     * The person or organisation who created this module.
     *
     * @return string The module author's name.
     */
    public function customModuleAuthorName(): string
    {
        return 'Rico Sonntag';
    }

    /**
     * The version of this module.
     *
     * @return string The module version string.
     */
    public function customModuleVersion(): string
    {
        return '0.1.0-dev';
    }
};
