<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

use function ctype_digit;
use function max;
use function min;
use function strlen;

/**
 * The six admin-editable per-signal scoring caps, projected to and from persisted preferences. This
 * value object is the SINGLE source for the caps' preference keys, their enriched-aligned defaults and
 * their shared input bounds — shared by the control-panel writer, the panel view and the live-ingest
 * reader, so those three never drift. The band thresholds (85/70/55/40) are NOT here: they are fixed
 * {@see \MagicSunday\ObituaryMatcher\Scoring\Classifier} constants, shown read-only, never an input.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ScoreWeights
{
    /**
     * The six editable caps in display order: logical field => {preference key, default, min, max}.
     * The defaults MUST equal the enriched profile's base caps so {@see self::defaults()} projects to
     * {@see ScoreConfig::enriched()} verbatim (asserted in the value object's test).
     *
     * @var array<string, array{key: string, default: int, min: int, max: int}>
     */
    public const array FIELDS = [
        'maxName'         => ['key' => 'score_max_name', 'default' => 35, 'min' => 0, 'max' => 100],
        'maxBirth'        => ['key' => 'score_max_birth', 'default' => 25, 'min' => 0, 'max' => 100],
        'maxPlace'        => ['key' => 'score_max_place', 'default' => 10, 'min' => 0, 'max' => 100],
        'maxPlausibility' => ['key' => 'score_max_plausibility', 'default' => 10, 'min' => 0, 'max' => 100],
        'maxPenalty'      => ['key' => 'score_max_penalty', 'default' => 50, 'min' => 0, 'max' => 100],
        'ambiguityGap'    => ['key' => 'score_ambiguity_gap', 'default' => 10, 'min' => 0, 'max' => 100],
    ];

    /**
     * Constructor.
     *
     * @param int $maxName         Maximum points for a name signal.
     * @param int $maxBirth        Maximum points for a birth-date signal.
     * @param int $maxPlace        Maximum points for a place signal.
     * @param int $maxPlausibility Maximum points for a plausibility signal.
     * @param int $maxPenalty      Maximum conflict penalty that can be applied.
     * @param int $ambiguityGap    Score gap below which a match is considered ambiguous.
     */
    public function __construct(
        public int $maxName,
        public int $maxBirth,
        public int $maxPlace,
        public int $maxPlausibility,
        public int $maxPenalty,
        public int $ambiguityGap,
    ) {
    }

    /**
     * The enriched-aligned defaults, applied whenever nothing is persisted.
     *
     * @return self The default weights.
     */
    public static function defaults(): self
    {
        return new self(
            self::FIELDS['maxName']['default'],
            self::FIELDS['maxBirth']['default'],
            self::FIELDS['maxPlace']['default'],
            self::FIELDS['maxPlausibility']['default'],
            self::FIELDS['maxPenalty']['default'],
            self::FIELDS['ambiguityGap']['default'],
        );
    }

    /**
     * Reads the weights leniently through a preference reader. Each cap is read via its key (with its
     * default handed to the reader); a non-integer or out-of-range stored value falls back to the
     * default or is clamped into bounds rather than throwing — a persisted value is already validated
     * on write, so this only hardens against a hand-tampered preference.
     *
     * @param callable(string, string): string $get A reader: (preference key, default as string) => stored string.
     *
     * @return self The resolved weights.
     */
    public static function fromReader(callable $get): self
    {
        /** @var array<string, int> $values */
        $values = [];

        foreach (self::FIELDS as $field => $meta) {
            $values[$field] = self::clamp(
                $get($meta['key'], (string) $meta['default']),
                $meta['default'],
                $meta['min'],
                $meta['max'],
            );
        }

        return new self(
            $values['maxName'],
            $values['maxBirth'],
            $values['maxPlace'],
            $values['maxPlausibility'],
            $values['maxPenalty'],
            $values['ambiguityGap'],
        );
    }

    /**
     * Projects the weights onto the enriched profile, overriding only the six editable base caps.
     *
     * @return ScoreConfig The enriched profile carrying these caps.
     */
    public function toScoreConfig(): ScoreConfig
    {
        return ScoreConfig::enrichedWith(
            $this->maxName,
            $this->maxBirth,
            $this->maxPlace,
            $this->maxPlausibility,
            $this->maxPenalty,
            $this->ambiguityGap,
        );
    }

    /**
     * Parses a raw stored value into a clamped integer: a clean unsigned-digit integer is clamped into
     * the inclusive [$min, $max] bounds; any other shape (empty, signed, decimal, non-numeric, or a
     * pathologically long digit run) falls back to $default.
     *
     * @param string $raw     The raw stored value.
     * @param int    $default The fallback for an unusable value.
     * @param int    $min     The inclusive lower bound.
     * @param int    $max     The inclusive upper bound.
     *
     * @return int The resolved, in-bounds integer.
     */
    private static function clamp(string $raw, int $default, int $min, int $max): int
    {
        if (!ctype_digit($raw) || (strlen($raw) > 9)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }
}
