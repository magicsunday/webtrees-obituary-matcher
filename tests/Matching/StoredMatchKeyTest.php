<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function hash;

/**
 * Behavioural tests for the canonical row-key derivation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(StoredMatchKey::class)]
#[UsesClass(UrlNormalizer::class)]
final class StoredMatchKeyTest extends TestCase
{
    /**
     * The key is the SHA-256 of the identity-normalised URL.
     *
     * @return void
     */
    #[Test]
    public function fromUrlIsSha256OfNormalisedUrl(): void
    {
        $url = 'https://trauer.example/a?utm_source=newsletter';

        self::assertSame(
            hash('sha256', UrlNormalizer::normalizeForIdentity($url)),
            StoredMatchKey::fromUrl($url)
        );
    }

    /**
     * Two URL spellings that normalise equal (host case + tracking param) share one key.
     *
     * @return void
     */
    #[Test]
    public function spellingsThatNormaliseEqualShareAKey(): void
    {
        self::assertSame(
            StoredMatchKey::fromUrl('https://Trauer.Example/a'),
            StoredMatchKey::fromUrl('https://trauer.example/a?utm_source=newsletter')
        );
    }
}
