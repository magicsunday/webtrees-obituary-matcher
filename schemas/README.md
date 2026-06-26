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

This contract is the **target** transport. The system today still talks over a
file-based queue (the matcher's `Queue/*` classes ↔ a cron-drained finder); the
ordered migration from that queue to REST — what maps to what, the incremental
steps, and the cutover criterion — is tracked in the REST transition epic (`#66`).
The JSON shapes carry over almost 1:1, so adopting REST is a transport swap, not a
data-model rewrite.

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
matcher in turn dedups **across** finders — both MUST use the SAME algorithm:
lowercase scheme + host, strip the default port, drop the fragment, remove
tracking params (`utm_*`, `fbclid`, `gclid`), sort the remaining query params,
keep the path verbatim. Anything else double-counts or wrongly collapses notices.

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
