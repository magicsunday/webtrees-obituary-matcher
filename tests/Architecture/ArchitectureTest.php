<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Architecture;

use Fisharebest\Webtrees\DB;
use MagicSunday\ObituaryMatcher\Webtrees\HeadlessBootstrap;
use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Architecture rules executed by phpat through PHPStan (loaded through
 * magicsunday/coding-standard's opt-in phpstan/phpat.neon preset).
 *
 * Deptrac first, phpat only where Deptrac cannot. The module's layering — a pure
 * core (Domain, Parsing, Support, Scoring, Queue, Matching) plus the webtrees-free
 * Ui, and the `Webtrees` adapter as the ONLY layer allowed to reach into
 * `Fisharebest\Webtrees` — is enforced by Deptrac (`deptrac.yaml`). What stays here
 * is the one rule Deptrac cannot express: database access confined to `*Repository`
 * classes, keyed on a class name inside the `Webtrees` layer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class ArchitectureTest
{
    /**
     * Namespace root shared by every production class.
     *
     * @var string
     */
    private const string NAMESPACE_ROOT = 'MagicSunday\\ObituaryMatcher';

    /**
     * Query ISSUANCE is the exclusive responsibility of repositories. The marker
     * covers both the entry point — webtrees' `DB` query-builder facade
     * (`DB::table(...)`/`DB::query(...)`) — and the underlying `Illuminate\Database`
     * query layer it returns (`Builder`, `Expression`, `Capsule\Manager`,
     * `ConnectionInterface`), so a class cannot escape the rule by taking a query
     * builder directly instead of through the facade. The pure layers already
     * cannot touch the framework at all (Deptrac's `WebtreesFramework` layer in
     * `deptrac.yaml`), so the net effect of this rule lands inside the `Webtrees` adapter: only a
     * `*Repository` may issue SQL, never the value-object adapter, the date mapper,
     * or a future module/controller/review-UI. Scattering `DB::table(...)` across
     * the adapter would spread query-shape decisions over every class and make it
     * impossible to reason about which class touches which table.
     *
     * Why phpat: a sub-layer boundary keyed on a class NAME (`*Repository`), inside the
     * `Webtrees` layer — Deptrac checks a class against every layer it belongs to and
     * cannot grant one part of a layer an edge the rest of it is denied.
     *
     * Repositories are selected by the `*Repository` class-name suffix rather than
     * a dedicated namespace because the single repository lives in the `Webtrees`
     * adapter alongside the non-querying adapter classes; the suffix keeps any
     * future repository automatically exempt without a namespace move.
     *
     * The single {@see HeadlessBootstrap} composition root is the one narrow
     * exemption: as the request-less CLI bootstrap it must call `DB::connect()` /
     * `DB::connection()` to ESTABLISH the connection (the connection bootstrap plus
     * the no-clobber probe), but it issues no query — no `DB::table()`/`DB::query()`,
     * no Eloquent. Confining connection setup to the composition root is exactly the
     * intent of a composition root, so exempting only that one class keeps query
     * issuance confined to `*Repository` while letting the bootstrap wire the
     * connection every repository then queries through.
     */
    #[TestRule]
    public function databaseAccessIsConfinedToRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT),
                    Selector::Not(Selector::classname('#Repository$#', true)),
                    Selector::Not(Selector::classname(HeadlessBootstrap::class)),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Test')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(
                Selector::classname(DB::class),
                Selector::inNamespace('Illuminate\\Database'),
            )
            ->because('Query issuance (DB::table/DB::query/Eloquent) is confined to repositories; no other class may issue SQL through the webtrees DB facade or the Illuminate query layer. The HeadlessBootstrap composition root is exempted only to call DB::connect/DB::connection for connection bootstrap — it issues no query');
    }
}
