## Overview
This repository hosts the **obituary-matcher scoring engine** — a pure-PHP, explainable entity-matching library that scores webtrees individuals against public obituary notices to suggest missing death dates. For each candidate person × obituary pair it produces a transparent 0–100 score, a qualitative band, and a list of human-readable reasons, so an admin can review and confirm a suggestion rather than trusting an opaque verdict.

The Composer package is a `library` (`magicsunday/webtrees-obituary-matcher`, namespace `MagicSunday\ObituaryMatcher\`). It has **no JavaScript, no chart layer, and no webtrees runtime dependency** — the only runtime requirement is PHP. The webtrees module that will host the engine (a control-panel review UI plus individual-page integration and death-date write-back) is a later phase; this repository is the engine that module will embed.

### Hybrid project context
The full product is two cooperating pieces:
- **This repository (the engine)** — pure PHP. Parses obituary text into typed facts, scores each tree-candidate against each obituary, classifies the result into a confidence band, and detects data conflicts. Deterministic, side-effect-free, fully unit-tested.
- **A future webtrees module** — embeds this engine, renders the explainable review screens inside the active webtrees theme (admin-only, control-panel + individual-page surfaces), and on admin confirm writes the death date plus a source citation back into the tree. **Nothing is ever written automatically.**
- **A separate Python + Playwright feeder** — a standalone crawler that collects public obituary notices and normalises them into the engine's input shape. It lives outside this repository and is not a Composer dependency.

## Setup/env
- PHP 8.3–8.5 is required. Composer installs dependencies into `.build/vendor` and binaries into `.build/bin` (see `config.vendor-dir` / `config.bin-dir` in `composer.json`).
- There is **no Node.js toolchain** — the engine ships no frontend assets. The only Node touch-point is `npx jscpd` for the copy/paste check, invoked through `composer ci:test:cpd`.
- Run all PHP tooling inside the webtrees buildbox container — **never on the host**. From the webtrees root:
  ```
  docker compose run --rm buildbox bash -c "cd app/vendor/magicsunday/webtrees-obituary-matcher && composer ci:test"
  ```
  (Substitute the module path for your checkout if it differs; the form above is the canonical buildbox invocation.)

## Build & tests
- **`composer ci:test` MUST run green before every commit** — it runs the full gate: phplint, PHP-CS-Fixer (dry-run), PHPStan, Rector (dry-run), jscpd, and PHPUnit. Catch every issue locally before it reaches GitHub CI.
- Individual checks:
  - `composer ci:test:php:lint` — phplint syntax check.
  - `composer ci:test:php:cgl` — PHP-CS-Fixer in `--dry-run` mode (style gate).
  - `composer ci:test:php:phpstan` — PHPStan analysis.
  - `composer ci:test:php:rector` — Rector in `--dry-run` mode.
  - `composer ci:test:cpd` — jscpd copy/paste detection over `src` + `tests`.
  - `composer ci:test:php:unit` — PHPUnit suite.
- Single PHPUnit test: `composer ci:test:php:unit -- --filter TestClassName`.
- Auto-fix: `composer ci:cgl` (apply PHP-CS-Fixer changes), `composer ci:rector` (apply Rector changes). Run these BEFORE the first `ci:test` + audit-loop so style noise never mixes with substantive review.
- PHPStan runs with no baseline — the baseline file is intentionally absent so future drift cannot be silently ignored. Every change fixes the underlying type defect; never add a `@phpstan-ignore`.
- jscpd, Rector and PHP-CS-Fixer are configured via `.jscpd.json`, `rector.php` and `.php-cs-fixer.dist.php`. PHP-CS-Fixer covers `src/` **and** `tests/`; Rector intentionally covers `src/` only (running Rector over `tests/` reprints and empties the `@author` docblocks).

## Architecture

The engine is a small set of side-effect-free layers. Input (a parsed obituary plus a list of tree candidates) flows through the scorers into a classified, explainable result.

```
ObituaryRecord  +  PersonCandidate[]            (typed input — Domain layer)
        │
        ▼
   Scoring layer
     ├─ NameScorer    ─┐
     ├─ BirthScorer    ├─→  SignalScore per signal  ─┐
     ├─ PlaceScorer   ─┘                              │
     └─ ConflictDetector ─→  negative evidence ───────┤
        │                                             ▼
        ▼                                        MatchEngine
   Classifier  ─→  Band + ambiguity flag  ──────────→  ClassifiedMatch
                                                     (+ MatchExplanation, RunnerUp)
```

### `src/Domain` — value objects
The typed vocabulary shared across every layer. All are `final readonly` value objects or pure enums — no behaviour beyond construction, equality, and small derived accessors:
- **Input shapes** — `ObituaryRecord` (one parsed obituary), `PersonCandidate` (one tree individual), `PersonName`, `Place`, `DateValue` / `DateRange` (with `DatePrecision` / `DateRangeStatus`), `Gender`.
- **Scoring config** — `ScoreConfig` carries the weights and thresholds (the signal maxima, the conflict penalty cap, the ambiguity gap) as constructor defaults, so a caller can re-tune the engine without touching the scorers.
- **Result shapes** — `SignalScore` (one signal's score + max + reasons), `ConflictReason` / `ConflictResult` / `ConflictSeverity` (the negative evidence), `Classification` (`Band` + ambiguity flag + reasons), `ClassifiedMatch`, `MatchExplanation`, `RunnerUp` (the closest-scoring alternative candidate).

### `src/Scoring` — the matching engine
The positive-evidence scorers (`NameScorer`, `BirthScorer`, `PlaceScorer`) each consume the typed input plus a `ScoreConfig` and emit a `SignalScore`. `ConflictDetector` is the **sole source of negative evidence** — it compares candidate and obituary fields and emits conflicts. `MatchEngine` orchestrates the scorers, applies the conflict penalty, picks the runner-up, and hands the totals to `Classifier`, which maps the final score to a `Band` and sets the ambiguity flag. (Some of these orchestration classes are landing on the active feature branch; the scorers and the full Domain vocabulary are in place.)

### `src/Parsing` — fact extraction
`ObituaryNameParser` and `ObituaryDateParser` turn raw obituary text into the typed `Domain` shapes the engine scores against (names, birth-name markers, widow markers, dates in the imprecise GEDCOM-style forms an obituary uses).

### `src/Support` — pure helpers
DB-free, framework-free utilities the scorers lean on: `Normalizer` (case/diacritic folding, title/affix stripping), `KoelnerPhonetik` + `PhoneticEncoder` (German-phonetic name matching), `GivenNameVariants` (given-name cluster lookup so "Hans"/"Johann" count as the same person).

## Scoring model
The total score is a weighted sum of independent positive signals, capped at 100, with one negative-evidence channel:

| Signal | Max points | Notes |
|---|---|---|
| **Name** | **45** | Full-exact / birth-name / phonetic / variant matches, scaled down for partial matches. |
| **Birth** | **30** | Same year / close year / same month+year, etc. |
| **Place** | **15** | Same place / nearby place across birth + residence places. |
| **Plausibility** | **10** | Plausible age at death, and "no death date already in the tree" — the engine only suggests where a date is actually missing. |

- **`ConflictDetector` is the only negative evidence.** A field contradiction (e.g. a birth-date mismatch) is emitted as a `ConflictResult`; a **hard** conflict caps the band regardless of how high the positive signals scored. Penalty is bounded by the configured cap.
- **Bands** (defaults in `ScoreConfig` / `Band` mapping): score ≥ **85** → `strong`, ≥ **70** → `probable`, ≥ **55** → `possible`, ≥ **40** → `weak`, below → `none`.
- **Ambiguity gap = 10.** When the best is at least a *possible* match (score ≥ **55**) **and** the runner-up candidate scores within 10 points of it, the result is flagged `ambiguous` so the reviewer knows two people fit almost equally well. A weak/none best is never flagged, so low-confidence noise is not surfaced.
- **Death date is an extracted fact, NOT a positive signal.** The engine never scores points for the obituary having a death date — the death date is the *answer* the suggestion delivers, carried in `extractedFacts`, not evidence that the match is correct. Scoring the death date would be circular.
- **Missing data scores 0, never negative.** An absent place or birth year contributes 0 points to its signal; it is not a conflict. Only a present-and-contradicting value is negative evidence.

## Design principles
- Priority order on conflict: **KISS > SOLID > DRY > YAGNI > GRASP > Law of Demeter > Separation of Concerns > Convention over Configuration**.
- `declare(strict_types=1)`, no `mixed`, no `empty()`, no nested ternaries, typed class constants (`private const int X = …`), `final readonly` value objects, private constructor on static-only utility classes, qualified `use function` imports for built-ins. Attributes go AFTER the PHPDoc block. In `&&` / `||`, parenthesise only comparison / instanceof operands, never a unary `!`. English-only code, comments, and commit text.
- **PHPDoc on every class, constant, method, and constructor.** Each class/enum/interface (and every test class) carries a 1–2 line description followed by:
  ```
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
  ```
  Every class constant gets a one-line PHPDoc; promoted constructor properties are documented via `@param`. All `@param` / `@return` / `@var` / `@throws` descriptions start capitalised.
- **Multi-condition formatting.** An `if` / `while` / `elseif` with more than one `&&` / `||` operand breaks one condition per line: `if (` on its own line, the operator at the START of each continuation line, `) {` on its own line. A single-condition statement stays inline.
- One class per file.

## TDD & testing
- Write the failing test FIRST, then the minimal fix — every bug and every feature, no exceptions. The test files mirror `src/` under `tests/` and are themselves held to the full code-style + PHPDoc rules (tests are NOT exempt).
- Use PHPUnit attributes (`#[Test]`, `#[CoversClass]`, `#[UsesClass]`, `#[DataProvider]`), complete coverage annotations, and zero tolerance for notices / warnings / risky / deprecations (`phpunit.xml` sets `failOn*` for all of them).
- Assert **real value-equality against curated fixtures** — a scorer test pins the exact `SignalScore` (score + reasons), not just `assertGreaterThan(0)`. A scenario that straddles a band boundary or an ambiguity gap is the discriminator that makes a regression fail.
- Every pure-helper branch gets a `DataProvider` row. Don't over-mock the unit under test — the engine is deterministic, so feed it real typed input.

## Commit & git discipline
- Commit subject is a **capital-verb imperative** matching `^(GH-<N>: )?[A-ZÄÖÜ]` — no conventional-commit prefixes (`feat:` / `fix:` / `chore:`), no lowercase or path starts. Use the `GH-<N>: ` prefix for issue-tied commits.
- **Never** add a `Co-Authored-By:` trailer or any AI attribution.
- Commit as **Rico Sonntag <mail@ricosonntag.de>** (the private address, never a work address).
- Granular, logical commits — one concern each; keep CGL / style-only fixes in a separate commit from feature changes. Commit only verified-working code (full `composer ci:test` green first).

## No INTERNA / PII in artifacts
Never write private genealogy data (real person names, real obituary URLs, counts from a real tree), local absolute paths, container or host names, or personal contact details into commits, code, committed docs, or GitHub issues / PRs. Describe everything in absolute, rule-based terms. Do not write a fully-qualified foreign `owner/repo#num` or an upstream issue/PR URL into a commit message or issue/PR comment (GitHub auto-posts an irreversible backlink) — use a non-autolinking form (backticks in Markdown, drop the `#`/URL in commit messages).

## CI & review gates
- Full `composer ci:test` green before **every** commit and every tag/release, locally and in GitHub Actions.
- For every change, run the relevant reviewers (correctness + maintainability + testing + project-standards always; the conditional lenses — performance, simplicity, pattern-recognition — when their triggers match) and iterate fix → audit until two consecutive clean rounds AND `composer ci:test` is green.
- Keep this `AGENTS.md` in lockstep with the code. If a section here describes behaviour the code no longer has, fix the doc in the same commit.

## Phase roadmap
- **Phase 1 — scoring engine (current).** The pure-PHP engine in this repository: typed Domain vocabulary, the positive-signal scorers, the conflict detector, the classifier/`MatchEngine` orchestration, and obituary parsing — all deterministic and unit-tested. No webtrees coupling, no UI, no persistence.
- **Phase 2 — webtrees integration.** Obituary detail-enrichment, a webtrees adapter that feeds tree candidates into the engine, the admin review UI (control-panel + individual-page surfaces, in the active webtrees theme), and the confirmed-suggestion write-back (death date + source citation). See `design/` for the UI/UX brief.
- **Later phases.** Suggestion persistence, reverse matching (start from an obituary, find the tree person), and a possible learning/ML scoring refinement.
