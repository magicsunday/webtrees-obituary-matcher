<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Contract;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the toolchain capability the contract gate depends on: opis/json-schema must validate
 * JSON-Schema 2020-12 AND assert the `format` keyword (date), so a calendar-invalid date in the
 * untrusted finder response is rejected rather than passed through as an annotation. This is the
 * format-assertion regression guard; if it goes red, the validator no longer enforces dates.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class FormatAssertionTest extends TestCase
{
    /**
     * The schema fragment used to probe date-format enforcement.
     */
    private const string DATE_SCHEMA = '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"string","format":"date"}';

    /**
     * A real calendar date validates.
     *
     * @return void
     */
    #[Test]
    public function aValidDatePasses(): void
    {
        $result = (new Validator())->validate('2023-02-28', self::DATE_SCHEMA);

        self::assertTrue($result->isValid());
    }

    /**
     * A calendar-invalid date is rejected — proves `format` is asserted, not annotation-only.
     *
     * @return void
     */
    #[Test]
    public function aCalendarInvalidDateIsRejected(): void
    {
        $result = (new Validator())->validate('2023-02-30', self::DATE_SCHEMA);

        self::assertFalse($result->isValid());
    }
}
