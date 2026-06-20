<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Individual;
use PHPUnit\Framework\Attributes\Test;

use function file_get_contents;
use function strip_tags;

/**
 * Verifies that the webtrees bootstrap, in-memory schema and fixture importer of
 * {@see IntegrationTestCase} work end to end: a GEDCOM fixture is imported into a
 * fresh tree and the resulting individual resolves with its real name.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class BootstrapSmokeTest extends IntegrationTestCase
{
    /**
     * Imports the candidate fixture tree and resolves the seeded individual.
     *
     * @return void
     */
    #[Test]
    public function importsAFixtureTreeAndResolvesAnIndividual(): void
    {
        $gedcom = file_get_contents(__DIR__ . '/../fixtures/candidates.ged');
        self::assertIsString($gedcom);

        $tree       = $this->importFixtureTree($gedcom);
        $individual = $this->individual('I1', $tree);

        self::assertInstanceOf(Individual::class, $individual);
        self::assertStringContainsString('Otto', strip_tags($individual->fullName()));
    }
}
