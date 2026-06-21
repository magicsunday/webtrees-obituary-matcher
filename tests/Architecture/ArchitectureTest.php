<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Architecture rules executed by phpat through PHPStan. Each `#[TestRule]`
 * method returns one rule that pins a structural invariant of the module so the
 * codebase cannot silently drift past the layering the rest of the production
 * code relies on.
 *
 * The whole module is split into a pure core that knows nothing about webtrees
 * and a single thin adapter layer that bridges the core to the framework:
 *
 *   - Domain    (immutable value objects + domain vocabulary; depends on nothing internal)
 *   - Parsing   (turns raw obituary text into Domain value objects)
 *   - Support   (pure helpers: normalisation, configuration, math)
 *   - Scoring   (explainable feature scorers over Domain values)
 *   - Queue     (candidate-selection + work-item plumbing over Domain/Parsing/Support)
 *   - Matching  (composition of Scoring/Queue into a match decision)
 *   - Webtrees  (THE ONLY adapter; may import `Fisharebest\Webtrees\*`)
 *
 * The load-bearing invariant is engine purity: the six pure layers above must
 * never depend on `Fisharebest\Webtrees`, so the scoring engine stays unit
 * testable without a webtrees runtime and reusable outside it. Only the
 * `Webtrees` adapter is allowed to reach into the framework.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class ArchitectureTest
{
    /**
     * Namespace root shared by every production class. Layer selectors below are
     * built by appending the layer segment to this prefix.
     *
     * @var string
     */
    private const string NAMESPACE_ROOT = 'MagicSunday\\ObituaryMatcher';

    /**
     * Fully-qualified namespace of the webtrees framework. The pure layers must
     * never select-depend on anything below it.
     *
     * @var string
     */
    private const string WEBTREES_NAMESPACE = 'Fisharebest\\Webtrees';

    /**
     * The pure layers form the engine core: none of them may depend on the
     * webtrees framework. This is the load-bearing invariant of the whole module
     * — the scoring engine must stay unit-testable and reusable without a
     * webtrees runtime, so every framework touch is confined to the `Webtrees`
     * adapter. A single violation here means a framework dependency has leaked
     * into the pure core.
     */
    #[TestRule]
    public function pureLayersDoNotDependOnWebtrees(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Domain'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Parsing'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Scoring'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Queue'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::WEBTREES_NAMESPACE))
            ->because('The pure scoring engine must stay free of the webtrees framework; only the Webtrees adapter may import Fisharebest\\Webtrees');
    }

    /**
     * Domain is the leaf layer: immutable value objects and domain vocabulary
     * that every other layer speaks. It depends on no other internal layer — the
     * dependency arrow always points towards Domain, never out of it. A Domain
     * class reaching into Parsing/Support/Scoring/Queue/Matching/Webtrees would
     * invert the layering and turn a value object into a service.
     */
    #[TestRule]
    public function domainDependsOnNoOtherInternalLayer(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Domain'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Parsing'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Scoring'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Queue'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Webtrees'),
            )
            ->because('Domain is the leaf vocabulary every layer speaks; it must not depend back into any layer that consumes it');
    }

    /**
     * Support holds pure helpers (normalisation, configuration, math). It is a
     * near-leaf layer that may only build on Domain. It must not reach into the
     * higher engine layers (Parsing/Scoring/Queue/Matching) or the Webtrees
     * adapter, which would create a cycle that turns a helper into a service.
     */
    #[TestRule]
    public function supportDependsOnlyOnDomain(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Parsing'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Scoring'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Queue'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Webtrees'),
            )
            ->because('Support is a leaf helper layer building only on Domain; the engine layers and the adapter live above it');
    }

    /**
     * Parsing turns raw obituary text into Domain value objects. It builds only
     * on Domain and must not depend on the layers that sit above it
     * (Support/Scoring/Queue/Matching) nor on the Webtrees adapter.
     */
    #[TestRule]
    public function parsingDependsOnlyOnDomain(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Parsing'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Scoring'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Queue'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Webtrees'),
            )
            ->because('Parsing only produces Domain values; it must not depend on Support, the higher engine layers, or the adapter');
    }

    /**
     * Scoring computes explainable feature scores over Domain values, using
     * Support helpers. It must not depend on the candidate-pipeline layers
     * (Queue/Matching) — Matching composes Scoring, never the reverse — nor on
     * the Webtrees adapter.
     */
    #[TestRule]
    public function scoringDependsOnlyOnDomainAndSupport(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Scoring'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Parsing'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Queue'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Webtrees'),
            )
            ->because('Scoring builds on Domain and Support only; Matching composes Scoring, so the inverse direction would create a cycle');
    }

    /**
     * Queue assembles candidate work items from Domain/Parsing/Support. It sits
     * below Matching (which composes it) and must not depend on Scoring,
     * Matching, or the Webtrees adapter.
     */
    #[TestRule]
    public function queueDependsOnlyOnDomainParsingAndSupport(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Queue'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Scoring'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Webtrees'),
            )
            ->because('Queue builds on Domain, Parsing and Support; Matching composes Queue, so it must not depend back up into Scoring/Matching or the adapter');
    }

    /**
     * Matching is the engine apex: it composes Scoring and Queue (over Domain
     * and Support) into a match decision. It is still part of the pure core, so
     * it must not depend on the Webtrees adapter — the framework boundary stays
     * one level above, in the adapter that drives Matching.
     */
    #[TestRule]
    public function matchingDoesNotDependOnTheAdapter(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Webtrees'))
            ->because('Matching is the pure engine apex; the Webtrees adapter drives Matching, never the reverse');
    }

    /**
     * The Webtrees adapter is the only layer allowed to touch the framework, but
     * it is still a thin bridge: it maps framework objects onto the pure core
     * (Domain/Support) and must not reach into the higher engine layers
     * (Parsing/Scoring/Queue/Matching). Keeping the adapter dependent only on
     * Domain and Support means swapping the adapter never drags the whole engine
     * along.
     */
    #[TestRule]
    public function webtreesAdapterDependsOnlyOnDomainAndSupport(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Webtrees'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Parsing'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Scoring'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Queue'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Matching'),
            )
            ->because('The Webtrees adapter is a thin bridge onto Domain and Support; it must not pull in the higher engine layers');
    }
}
