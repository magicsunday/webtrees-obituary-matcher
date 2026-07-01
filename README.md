[![Latest version](https://img.shields.io/github/v/release/magicsunday/webtrees-obituary-matcher?sort=semver)](https://github.com/magicsunday/webtrees-obituary-matcher/releases/latest)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue.svg)](https://github.com/magicsunday/webtrees-obituary-matcher/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/webtrees-obituary-matcher/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/webtrees-obituary-matcher/actions/workflows/ci.yml)


<!-- TOC -->
* [Obituary Matcher](#obituary-matcher)
  * [What it is](#what-it-is)
  * [Hybrid architecture](#hybrid-architecture)
  * [Scoring model](#scoring-model)
  * [Installation](#installation)
  * [Usage](#usage)
  * [Development](#development)
  * [Phase roadmap](#phase-roadmap)
  * [License](#license)
<!-- TOC -->


# Obituary Matcher
An explainable, pure-PHP entity-matching engine that scores [webtrees](https://www.webtrees.net) individuals against
public obituary notices to suggest **missing death dates**. For each candidate person × obituary pair it produces a
transparent `0–100` score, a qualitative band, and a list of human-readable reasons — so an admin can review and confirm
a suggestion rather than trusting an opaque verdict.

The Composer package is a `library` (`magicsunday/webtrees-obituary-matcher`, namespace `MagicSunday\ObituaryMatcher\`).
It has **no JavaScript, no chart layer, no database, and no webtrees runtime dependency** — the only runtime requirement
is PHP. The engine is deterministic and side-effect-free: the same input always produces the same explained result, and
nothing is ever written anywhere.


## What it is
A small set of side-effect-free layers. A parsed obituary plus a list of tree candidates flows through the scorers into
a classified, explainable result:

* **`Domain`** — the typed vocabulary shared across every layer: input shapes (`ObituaryRecord`, `PersonCandidate`,
  `PersonName`, `Place`, `DateValue` / `DateRange`, `Gender`), the `ScoreConfig` carrying the weights and thresholds, and
  the result shapes (`SignalScore`, `ConflictResult`, `Classification` / `Band`, `MatchExplanation`, `ClassifiedMatch`,
  `RunnerUp`). All are `final readonly` value objects or pure enums.
* **`Scoring`** — the positive-evidence scorers (`NameScorer`, `BirthScorer`, `PlaceScorer`, `PlausibilityScorer`) each
  emit a `SignalScore`. `ConflictDetector` is the **sole source of negative evidence**. `MatchEngine` orchestrates the
  scorers and applies the conflict penalty; `Classifier` maps the total to a `Band` and sets the ambiguity flag.
* **`Parsing`** — `ObituaryNameParser` and `ObituaryDateParser` turn raw obituary text into the typed `Domain` shapes the
  engine scores against (names, birth-name and widow markers, dates in the imprecise GEDCOM-style forms an obituary uses).
* **`Support`** — DB-free, framework-free helpers: `Normalizer` (case / diacritic folding, title / affix stripping),
  `KoelnerPhonetik` + `PhoneticEncoder` (German-phonetic name matching), `GivenNameVariants` (so "Hans" / "Johann" count
  as the same person).


## Hybrid architecture
The full product is two cooperating pieces; **this repository is only the engine**:

* **This repository — the engine.** Pure PHP. Parses obituary text into typed facts, scores each tree candidate against
  each obituary, classifies the result into a confidence band, and detects data conflicts. Deterministic,
  side-effect-free, fully unit-tested.
* **A future webtrees module.** Embeds this engine, renders the explainable review screens inside the active webtrees
  theme (admin-only, control-panel and individual-page surfaces), and on admin confirm writes the death date plus a
  source citation back into the tree. **Nothing is ever written automatically.**
* **A separate Python + Playwright finder.** A standalone crawler that collects public obituary notices and normalises
  them into the engine's input shape. It lives outside this repository and is not a Composer dependency.


## Scoring model
The total score is a weighted sum of independent positive signals, capped at `100`, with one negative-evidence channel:

| Signal           | Max points | Notes                                                                             |
|------------------|------------|-----------------------------------------------------------------------------------|
| **Name**         | **45**     | Full-exact / birth-name / phonetic / variant matches, scaled down for partials.   |
| **Birth**        | **30**     | Same year / close year / same month + year, etc.                                  |
| **Place**        | **15**     | Same place / nearby place across birth and residence places.                      |
| **Plausibility** | **10**     | Plausible age at death, and "no death date already in the tree".                   |

* **`ConflictDetector` is the only negative evidence.** A field contradiction (e.g. a birth-date mismatch) is emitted as
  a `ConflictResult`; a **hard** conflict caps the band regardless of how high the positive signals scored. The penalty
  is bounded by the configured cap.
* **Bands** (defaults in `ScoreConfig` / the `Band` mapping): score ≥ **85** → `strong`, ≥ **70** → `probable`,
  ≥ **55** → `possible`, ≥ **40** → `weak`, below → `none`.
* **Ambiguity gap = 10.** When the runner-up candidate scores within `10` points of the best, the result is flagged
  `ambiguous` so the reviewer knows two people fit almost equally well.
* **Death date is an extracted fact, NOT a positive signal.** The engine never scores points for the obituary having a
  death date — the death date is the *answer* the suggestion delivers, carried in `extractedFacts`, not evidence that the
  match is correct. Scoring it would be circular.
* **Missing data scores 0, never negative.** An absent place or birth year contributes `0` points to its signal; it is
  not a conflict. Only a present-and-contradicting value is negative evidence.

All weights, thresholds, the conflict-penalty cap, and the ambiguity gap are constructor defaults of `ScoreConfig`, so a
caller can re-tune the engine without touching the scorers.


## Installation
Requires **PHP 8.3** or later. Install with Composer:

```bash
composer require magicsunday/webtrees-obituary-matcher
```

The library has no Node.js toolchain and ships no frontend assets.


## Usage
Build a `PersonCandidate` and an `ObituaryRecord` from your data, score the pair, then classify it:

```php
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;

$candidate = new PersonCandidate(
    id:         'I123',
    gender:     Gender::Male,
    name:       new PersonName(givenNames: ['Johann'], callName: null, surname: 'Müller', birthSurname: null),
    birth:      DateRange::year(1940),
    birthPlace: new Place('Köln'),
    places:     [new Place('Köln')],
    death:      DateRange::unknown(),
);

$notice = new ObituaryRecord(
    name:       'Hans Müller',
    parsedName: new PersonName(givenNames: ['Hans'], callName: null, surname: 'Müller', birthSurname: null),
    birth:      DateRange::year(1940),
    death:      DateRange::year(2021),
    place:      new Place('Köln'),
    url:        'https://example.com/obituary/123',
    source:     'example.com',
);

$engine = new MatchEngine();
$best   = $engine->score($candidate, $notice);

// Classify the best result against the full candidate set (here: just one).
$classification = (new Classifier())->classify($best, [$best]);

$result = new ClassifiedMatch($best, $classification);

echo $result->classification->band->value(); // e.g. "strong"

foreach ($result->match->signals as $name => $signal) {
    printf("%s: %d/%d — %s\n", $name, $signal->score, $signal->max, implode('; ', $signal->reasons));
}
```

`MatchEngine::score()` returns a `MatchExplanation` (the clamped `0..100` total, the per-signal `SignalScore`s, the
`ConflictResult`, and the `extractedFacts` that include the suggested death date). `Classifier::classify()` maps it to a
`Classification` (`Band` + ambiguity flag + reasons); `ClassifiedMatch` bundles both for the caller.


## Development
All PHP tooling runs inside the project's build container — never on the host. Run the full gate before every commit:

```bash
composer ci:test
```

This runs, in order: phplint, PHP-CS-Fixer (dry-run), PHPStan (no baseline), Rector (dry-run), jscpd copy/paste
detection, and the PHPUnit suite. The individual checks are also available as `composer ci:test:php:lint`,
`composer ci:test:php:phpstan`, `composer ci:test:php:rector`, `composer ci:test:cpd`, and `composer ci:test:php:unit`;
auto-fixers are `composer ci:cgl` and `composer ci:rector`. GitHub Actions runs the same granular steps across
PHP 8.3, 8.4 and 8.5.

Every change is test-driven: write the failing test first, then the minimal fix. Scorer tests pin the exact
`SignalScore` against curated fixtures, and scenarios that straddle a band boundary or the ambiguity gap act as the
discriminators that make a regression fail.


## Phase roadmap
* **Phase 1 — scoring engine (current).** The pure-PHP engine in this repository: the typed Domain vocabulary, the
  positive-signal scorers, the conflict detector, the classifier / `MatchEngine` orchestration, and obituary parsing —
  all deterministic and unit-tested. No webtrees coupling, no UI, no persistence.
* **Phase 2 — webtrees integration.** A webtrees adapter that feeds tree candidates into the engine, the admin review UI
  (control-panel and individual-page surfaces, in the active webtrees theme), and the confirmed-suggestion write-back
  (death date + source citation).
* **Later phases.** Suggestion persistence, reverse matching (start from an obituary, find the tree person), and a
  possible learning / ML scoring refinement.


## License
This library is licensed under the [GNU General Public License v3.0 or later](LICENSE).
