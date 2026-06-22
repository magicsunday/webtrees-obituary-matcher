<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function view;

/**
 * Behavioural tests for the read-only `tab.phtml` template: it renders the suggestion list the
 * module passes in, escapes every output, links the source notice only for an HTTP(S) URL and
 * keeps the "Prüfen" action a disabled button (the per-individual review trigger ships later).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class TabViewTest extends TestCase
{
    /**
     * Initialises the webtrees translator so `I18N::translate()`/`I18N::plural()` can be called. The
     * setup mode loads no catalogue, so a translated message returns its key verbatim.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        I18N::init('en-US', true);
    }

    /**
     * Renders the tab template under a private view namespace pointed at the module's view folder.
     *
     * @param list<SuggestionViewModel> $vms The suggestions to render.
     *
     * @return string The rendered HTML.
     */
    private function renderTab(array $vms): string
    {
        View::registerNamespace('obituary', dirname(__DIR__, 2) . '/resources/views/');

        return view('obituary::tab', [
            'suggestions' => $vms,
            'individual'  => null,
        ]);
    }

    #[Test]
    public function rendersCountHostLinkAndDisabledButton(): void
    {
        $vm = new SuggestionViewModel(80, 'probable', '04.09.2023', 'https://trauer.example/a', 'trauer.example', 'pending', false, false);

        $html = $this->renderTab([$vm]);

        self::assertStringContainsString('trauer.example', $html);
        self::assertStringContainsString('href="https://trauer.example/a"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('disabled', $html);
    }

    #[Test]
    public function nonHttpRowHasNoHref(): void
    {
        $vm = new SuggestionViewModel(80, 'none', null, null, null, 'pending', false, false);

        self::assertStringNotContainsString('href=', $this->renderTab([$vm]));
    }
}
