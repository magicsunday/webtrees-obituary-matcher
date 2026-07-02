<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Contract;

use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function sprintf;

use const PREG_SET_ORDER;

/**
 * The published-contract gate for the URL-normalisation algorithm: the matcher's {@see UrlNormalizer}
 * MUST reduce every vector documented in `schemas/README.md` to exactly the key printed there, so the
 * spec a third-party finder implements and the matcher's own implementation can never drift. The vectors
 * are read straight out of the README table, so adding a row there — or changing the algorithm — without
 * keeping the two in lockstep reds this test.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(UrlNormalizer::class)]
final class UrlNormalisationContractTest extends TestCase
{
    /**
     * The published contract README carrying the URL-normalisation vector table (repo root).
     */
    private const string CONTRACT_README = __DIR__ . '/../../schemas/README.md';

    /**
     * The exact number of vectors the README's URL-normalisation table pins. This is a deliberate
     * tripwire: the algorithm is a versioned contract whose key is a hash input on both sides, so adding
     * or removing a vector is a contract change that MUST be made intentionally — bumping this count is the
     * conscious acknowledgement. A `>=` guard would let a silent table reformat drop vectors and stay green.
     */
    private const int EXPECTED_VECTORS = 17;

    /**
     * Every `| `input` | `normalised` |` row of the README's URL-normalisation vector table normalises,
     * through the matcher's own {@see UrlNormalizer}, to exactly the documented key.
     *
     * @return void
     */
    #[Test]
    public function everyDocumentedVectorNormalisesToItsPublishedKey(): void
    {
        $vectors = self::documentedVectors();

        // Assert the EXACT pinned count, not a lower bound: a silent table reformat or a dropped vector
        // must red the gate, not pass with reduced coverage (see EXPECTED_VECTORS).
        self::assertCount(self::EXPECTED_VECTORS, $vectors, 'The README URL-normalisation vector table changed; update EXPECTED_VECTORS intentionally.');

        foreach ($vectors as [$input, $expected]) {
            self::assertSame(
                $expected,
                UrlNormalizer::normalizeForIdentity($input),
                sprintf('Documented vector "%s" does not normalise to its published key.', $input),
            );
        }
    }

    /**
     * Extracts the `| `input` | `normalised` |` rows of the README's URL-normalisation table. The header
     * and the `| --- | --- |` separator carry no backticked cells, so the backtick-anchored pattern skips
     * them; only genuine vector rows (both cells backticked) are returned.
     *
     * @return list<array{0: string, 1: string}> The [input, normalisedKey] vectors.
     */
    private static function documentedVectors(): array
    {
        $readme = file_get_contents(self::CONTRACT_README);

        if ($readme === false) {
            self::fail('Unable to read the contract README.');
        }

        // Isolate the URL-normalisation section (its heading up to the next `### ` heading, or the end of
        // the file if it is the last section) so the row pattern below only ever sees THIS table — other
        // README tables must not be mistaken for vectors.
        if (preg_match('/### URL normalisation.*?(?=\n### |\z)/s', $readme, $section) !== 1) {
            self::fail('The README URL-normalisation section could not be located.');
        }

        // Both cells backticked → a genuine vector row; the header and `| --- | --- |` separator carry no
        // backticks and are skipped.
        preg_match_all('/^\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|\s*$/m', $section[0], $matches, PREG_SET_ORDER);

        $vectors = [];

        foreach ($matches as $match) {
            $vectors[] = [$match[1], $match[2]];
        }

        return $vectors;
    }
}
