<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\I18N;

/**
 * The texts that the module words differently from the webtrees core.
 *
 * Each text carries the name of the module as its context, so that its entry in the catalogue
 * does not replace the core text in the rest of the installation. Every source text is a literal
 * at its call so that the extraction of the catalogue finds it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class Wording
{
    /**
     * Private constructor, the class only offers static methods.
     */
    private function __construct()
    {
    }

    /**
     * Returns the label of the action that rejects a candidate.
     */
    public static function reject(): string
    {
        return ContextText::resolve(I18N::translateContext('webtrees-obituary-matcher', 'Reject'), 'Reject');
    }

    /**
     * Returns the hidden label of the column that holds the selection control of a row.
     */
    public static function select(): string
    {
        return ContextText::resolve(I18N::translateContext('webtrees-obituary-matcher', 'Select'), 'Select');
    }

    /**
     * Returns the label of the spouse relation of a person.
     */
    public static function spouse(): string
    {
        return ContextText::resolve(I18N::translateContext('webtrees-obituary-matcher', 'Spouse'), 'Spouse');
    }
}
