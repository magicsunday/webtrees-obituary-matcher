# Finder ↔ Matcher contract (v1)

The published, versioned docking interface between the matcher and any finder.
The **transport is decided (REST-only)**; the data shapes below are the **v1**
contract. The REST transition is tracked in epic `#66`.

## What this is

The **docking interface** between the (public) matcher and any (private or
third-party) finder is a **versioned contract**. The transport is decided:
**REST-only** (the finder is an HTTP server, the matcher a configurable client).
This folder holds the contract in two complementary forms:

- **JSON Schema** (`schemas/*.json`, JSON Schema 2020-12) — the **data shapes**
  (request body, response body, capabilities). Kept as separate files so they can
  be validated independently and published/versioned on their own.
- **OpenAPI 3.1** (`obituary-finder.openapi.yaml`) — the **REST surface** (paths,
  status codes, auth). Its bodies `$ref` the JSON Schema files, so there is a
  single source of truth for the data shapes.

A finder is "compatible" if it implements this OpenAPI surface and satisfies these
schemas — then it can be plugged in without changing the matcher (the matcher only
needs the finder's base URL + optional token in config).

This contract is the transport the matcher uses. It submits finder jobs and drains
results over this REST surface — the matcher's `Queue/*` classes wrap the HTTP calls
and the pending-jobs ledger. The file-drop queue the matcher originally shipped with
has been removed (the REST transition epic `#66`); the JSON shapes are unchanged from
that earlier queue, so a finder that satisfies these schemas plugs in as a pure
transport, not a data-model rewrite.

## Files

| File | Role |
|---|---|
| `schemas/job-request.schema.json` | `POST /jobs` body — a search job (PII-minimal, structured candidate facts; optional query hints). |
| `schemas/job-response.schema.json` | `GET /jobs/{jobId}` body — per-person notices **+ per-portal coverage**; job state; counts. |
| `schemas/capabilities.schema.json` | `GET /capabilities` body — portals, populated notice fields, features, supported schema versions. |
| `obituary-finder.openapi.yaml` | REST surface: `GET /capabilities`, `POST /jobs`, `GET /jobs/{jobId}`, `DELETE /jobs/{jobId}`. |

## Design decisions baked into the contract

- **Structured facts, not baked queries**: the request carries name parts,
  a birth `yearRange` (resolves ABT/BET/AFT without GEDCOM parsing), and full
  comma-separated places so the finder can consider every Ortsteil. `queryHints`
  remain as an optional convenience.
- **PII minimisation**: the request deliberately omits relatives — they
  are needed only for scoring in the matcher, never for searching. The original
  GEDCOM birth string is also NOT transmitted (only structured `exactDate`/
  `yearRange`). **Deceased-only obligation:** the matcher MUST only submit
  candidates it has reason to believe are deceased (apply the webtrees privacy
  filter first) and SHOULD suppress `residence` places for anyone who could be
  living — an obituary search of a living person would leak their name + birth +
  home to a third party. **Residual exposure (be honest):** the request still ships
  name(s), birth year/date and place hierarchies to a possibly third-party finder.
  That is the minimum a portal search needs, but it IS PII leaving the matcher —
  off-host finders therefore require TLS + token, and jobIds (in the URL path) may
  surface in the finder's access logs.
- **Coverage disambiguates "nothing found"**: every `PersonResult`
  carries `coverage[]` with per-portal `ok | failed | skipped`, so the matcher
  can tell a real miss from a portal outage before recording a negative result.
- **Consumer guards for the untrusted response** (JSON Schema can't express these):
  the matcher MUST (a) enforce a total response **byte ceiling** (e.g. 16–32 MB,
  reject before full parse) — per-field `maxLength`/`maxItems` bound each value but
  their product is still large; (b) **re-parse every date** and reject
  calendar-invalid values — the backstop `pattern` only checks shape, and `format`
  is annotation-only unless format-assertion is enabled; (c) drop any `results` key
  not in the submitted candidates and any `notices[].source` absent from `coverage`.
- **Finder stays stateless**: negative-result memory and re-search policy
  live in the matcher, keyed by `finderId` from capabilities.
- **Async by design**: `POST /jobs` returns `202` + jobId (with an optional
  `Retry-After`); the matcher polls `GET /jobs/{jobId}` through the
  queued → running → done/failed lifecycle. A `done` job always carries `results`.
  **No partial results:** `results` is only meaningful once `done`; the matcher
  polls until a terminal state rather than reading a running job. **Retention:** a
  done/failed job is retained for a finder-defined window, then `GET` returns 404 —
  the matcher must fetch results before then (this interacts with the matcher's
  finderId-keyed negative-result memory). `POST` is idempotent on jobId (see the
  409 path). **jobIds MUST be unique and SHOULD be content-derived via a salted,
  opaque hash** — so a 409 collision implies identical content (never a foreign
  client pre-seeding a jobId the matcher will reuse), and the id is not reversible
  to person data in the finder's logs.
- **One identifier constraint, stated once:** `jobId` matches
  `^[A-Za-z0-9_-]{1,128}$` and `personId` is 1–256 chars — IDENTICAL on the request
  and on every echo (response keys, path params). The patterns appear in several
  files for tooling, but they are ONE normative constraint each; changing one side
  only would silently break the round-trip.
- **Versioning is major-only**: `schemaVersion` is a pinned major
  (`const: 1`); capabilities advertise supported majors. There is no lenient
  "minor" tier — any contract change is a new major and the schemas stay strict
  (`additionalProperties: false`), which is also what we want for parsing the
  untrusted finder response.
- **Auth/TLS are deployment-conditional**, not contract-level: plain HTTP on
  loopback/internal network; TLS + bearer token once the endpoint leaves the host.
  The OpenAPI `security` lists both `bearerAuth` and `[]` (no auth) on purpose —
  the empty option is the loopback affordance. This is NOT "auth is optional
  everywhere": an off-host conformance profile MUST require `bearerAuth`; the
  permissive spec only reflects that a co-located finder needs none. The bearer
  token MUST be high-entropy, unique per deployment (not a shared global), and
  rotatable; the finder MUST reject it (and any request) over plain HTTP off-host,
  and SHOULD scrub `Authorization` from access logs.
- **Reverse-match reserved**: a `features.reverseMatch` flag exists as a
  placeholder; the reverse query mode is not specified in v1.

## Normative behaviour (clean-room obligations)

These rules cannot live in JSON Schema but are part of the contract — two
independent implementers (matcher and finder) must agree on them or they will
build incompatible software.

### Lifecycle & retry

- `done` and `failed` are **terminal**. `failed` is **NOT** retried by
  re-POSTing the same job: since jobIds SHOULD be content-derived, an identical
  candidate set yields the same jobId and the finder returns `409` with the
  existing job's ack — whose `state` may already be `done`/`failed`; the matcher
  then `GET`s for the actual results. To re-run, the matcher MUST first `DELETE`
  the job and then re-POST, **or** vary the jobId (mix a retry nonce into the
  hash). A finder MUST NOT silently re-run a job on a colliding jobId.
- A `404` from `GET /jobs/{id}` is disambiguated by the `Error.error` code:
  `unknown_job` (never existed — matcher bug / wrong id) vs `job_expired`
  (results were produced but the retention window passed → the matcher MAY start a
  fresh job). The finder advertises its retention window via
  `capabilities.retentionSeconds`; the matcher MUST fetch results within it.
- `DELETE /jobs/{id}` is **fully idempotent**: an unknown or already-deleted id is
  **not** an error — it returns `204`, never `404` (only `GET` distinguishes
  `unknown_job`/`job_expired`). This is what makes the re-run path above safe: the
  matcher can `DELETE` then re-POST without first checking whether the job exists.

### URL normalisation (the dedup key — must match byte-for-byte on both sides)

`notices[]` are deduplicated by normalised URL **inside** the finder, and the
matcher in turn dedups **across** finders — both MUST reduce a URL to the SAME
key. The algorithm below is fully deterministic: two clean-room implementations
following it produce byte-identical keys.

**Precondition.** The algorithm is defined for a **valid absolute `http`/`https`
URL** — exactly what a `notices[].source` is (the schema pins `format: uri`, an
`http(s)` scheme, a host, and no userinfo). Split it into its RFC 3986 §3
components — scheme, authority (`host[:port]`), path, query, fragment — by
structure, **not** with a language-specific parser (PHP's `parse_url`, Python's
`urlsplit`, etc. disagree on malformed input, so naming one would not be
reproducible). A conforming finder never emits a relative, userinfo-bearing or
otherwise non-absolute URL; the matcher keeps an internal no-throw fallback for
such a string (it returns the trimmed, lower-cased input, never a malformed
`://…`) but that path is implementation-internal and **not** part of this
interoperability contract. Apply the steps **in order**:

1. **Drop** the fragment (`#…`). (Userinfo is already excluded by the schema.)
2. **Scheme** → ASCII lower-case. **Host** → ASCII lower-case. A finder MUST emit
   the host as lower-case ASCII — an IDN as its punycode **A-label**
   (`xn--…`) — so host normalisation is ASCII case-folding **only**: there is
   **no** punycode↔Unicode transcoding, and **no** IPv6-literal, trailing-dot, or
   host percent-decoding canonicalisation (`ä.example` and `xn--4ca.example` are
   therefore distinct keys — emit one form consistently). *(The matcher additionally
   applies a Unicode-aware lower-case defensively, so a stray upper-case non-ASCII
   host still folds; a conforming ASCII host makes that a no-op.)*
3. **Port**: strip it when it equals the scheme default (`http`→80, `https`→443);
   otherwise keep `:port`.
4. **Path**: an empty path becomes `/` (so `https://h` and `https://h/` share one
   key; a non-root trailing slash like `/a/` stays distinct from `/a`). The path is
   otherwise kept verbatim — **no** dot-segment collapsing — then
   percent-normalised (step 6).
5. **Query** (if any): split on `&` (only `&`; `;` is **not** a separator) and
   **drop** every empty field (a `&&` run, or a leading/trailing `&`).
   Percent-normalise each remaining field (step 6) **first**, then take its **name**
   as the bytes before the first literal `=` (any further `=` and everything after
   it is the value; an encoded `=` — `%3D` — is not a separator). Drop the field
   when the ASCII-lower-cased name starts with `utm_` **or** is exactly `fbclid`,
   `gclid` or `mc_eid` (only this test folds case; a retained field keeps its
   normalised bytes, and a bare `a` stays distinct from `a=`). **Sort** the retained
   fields by **unsigned-byte lexicographic order** (a bytewise `memcmp` — **not**
   numeric, locale, natural, Unicode-collation or case-folded; two identical fields
   compare equal so their order is immaterial). Join with `&`.
6. **Percent-encoding** (RFC 3986 §6.2.2, applied to the path and to each retained
   query field) in **one left-to-right pass over the original bytes** (generated
   output is never rescanned, so a decoded byte can never form a new escape). An
   escape is `%` followed by **exactly two ASCII hex digits**; a malformed `%`,
   `%1` or `%GG` is left byte-for-byte unchanged. Upper-case the two hex digits of
   every escape, and **decode** `%XX` when `XX` is an *unreserved* character
   (`A`–`Z` `a`–`z` `0`–`9` `-` `.` `_` `~`). Every other escape stays encoded — an
   encoded reserved byte is distinct from its literal (`%2F` ≠ `/`, `%20` stays
   `%20`, `%25` stays `%25`). A literal `+` is a distinct query byte and is **not**
   folded to a space (a finder that means a space MUST emit `%20`). Decoding an
   unreserved escape MAY materialise a `.`/`..` path segment (`%2E%2E` → `..`); it
   is kept verbatim, as step 4 does no dot-segment removal.
7. **Rebuild** `scheme://host[:port]path[?query]`.

The algorithm is **idempotent** — normalising an already-normalised key is a no-op
— and **versioned by this document**: the key is a hash input on both sides, so any
change to these rules is a breaking contract change, not a silent one.

These vectors pin the key byte-for-byte (a clean-room implementation MUST reproduce
them exactly; the matcher's own `UrlNormalizer` is tested against this same table):

| Input | Normalised key |
| --- | --- |
| `https://Example.test/a?utm_source=x&id=7#frag` | `https://example.test/a?id=7` |
| `https://example.test/a?fbclid=z` | `https://example.test/a` |
| `HTTP://Example.test:80/x` | `http://example.test/x` |
| `https://example.test:443/a` | `https://example.test/a` |
| `https://example.test` | `https://example.test/` |
| `https://example.test/a?b=2&a=10&a=1` | `https://example.test/a?a=1&a=10&b=2` |
| `https://example.test/a?x=%2fy` | `https://example.test/a?x=%2Fy` |
| `https://example.test/%41%62` | `https://example.test/Ab` |
| `https://example.test/a?x=%20y` | `https://example.test/a?x=%20y` |
| `https://example.test/a?x=a+b` | `https://example.test/a?x=a+b` |
| `https://example.test/a?promo.code=1` | `https://example.test/a?promo.code=1` |
| `https://example.test/a?%75tm_source=x&id=1` | `https://example.test/a?id=1` |
| `https://example.test/a/%2E%2E/b` | `https://example.test/a/../b` |
| `https://example.test/a?a&2&10&A` | `https://example.test/a?10&2&A&a` |
| `https://example.test/a?b&&a=&a&` | `https://example.test/a?a&a=&b` |
| `https://example.test/a?x=%GG%1%` | `https://example.test/a?x=%GG%1%` |
| `https://example.test/a?a%3db=c` | `https://example.test/a?a%3Db=c` |

### Producer obligations (Finder MUST) — the finder dev reads the schemas, so they are listed here too

- For every requested portal it **actually searched** for a person (not skipped,
  not failed), it MUST emit a `coverage[]` entry with `status: ok` and the
  `noticeCount` (which is `0` when nothing was found). A successfully-searched-but-
  empty portal is the case `coverage` exists to capture; omitting it makes
  "searched, nothing found" indistinguishable from "not searched". A processed
  person therefore always has a non-empty `coverage[]` (the schema enforces
  `minItems: 1`).
- For every requested portal it **cannot** search (not offered, or down), emit a
  `coverage[]` entry with `status: skipped` (not offered) / `failed` (down) and a
  `message` — never silently omit it, or the matcher's per-portal memory is wrong.
  (`PortalCoverage.portal` MAY then be an id outside the finder's own capabilities.)
- Every `notices[].source` MUST appear in that person's `coverage[]` with
  `status: ok`; `noticeCount` MUST equal that portal's notice count. A
  self-detected inconsistency SHOULD be reported in `warnings[]`, never shipped
  silently (the matcher drops unreconciled notices).
- **Calendar convention (whole contract):** EVERY `date` and `date-time` field on
  the request AND the response — `Notice.death`/`birth`/`funeralDate`, the request's
  `BirthSpec.exactDate`, and all `fetchedAt`/`startedAt`/`finishedAt` timestamps —
  is **proleptic Gregorian**, `YYYY-MM-DD` (or RFC 3339 date-time). There is no
  Julian-cutover ambiguity, so a pre-1582 date is unambiguous. A notice that yields
  only a month/year (no day) sets `death: null` — the confirm-gate needs a
  day-precise date, so a fabricated day is forbidden. Timestamps SHOULD be UTC
  (`Z`).
- A place `kind` (e.g. `death-hint`, `burial`) is **advisory**: a finder MAY use
  it for routing/weighting but MUST NOT require it.

### Capabilities caching

`GET /capabilities` is stable per `finderVersion`. The matcher SHOULD cache it and
refetch on a `finderVersion` change or a TTL; a finder SHOULD send `ETag` /
`Cache-Control`. A changed capability set (a portal disappears, `relatives` starts/
stops) invalidates the matcher's `finderId`-keyed assumptions for the next job.

## How to validate (later, when adopted)

The schemas are standard JSON Schema 2020-12 and the OpenAPI is 3.1 (same
dialect). Validation/linting would run via the buildbox container (no host
Node/PHP), e.g. a JSON-Schema validator for fixture payloads and an OpenAPI
linter for the YAML. **Enable format-assertion mode** in the validator: in 2020-12
`format` (date/date-time/uri) is annotation-only by default, so dates would not be
checked — the date fields therefore also carry a backstop `pattern`, but the
validator should still assert formats for the untrusted response. Note: the
OpenAPI `$ref`s the schema files by relative path —
some tools need the document bundled/resolved first. The schema `$id`s use the same
relative layout (`…/main/schemas/<file>`, no extra version segment — the major
lives in `schemaVersion`/capabilities, a future v2 is a distinct file), so an
`$id`-honouring bundler and a path resolver agree. Publishing the contract means
placing the files under `schemas/` in the public matcher repo.
