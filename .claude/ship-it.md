# ship-it profile — AziLaPranz

A Laravel rebuild of a 2011-era PHP restaurant directory, kept alive almost
entirely for its **search rankings**. 552 venue pages across 7 cities, whose
URLs, `<title>`s and `<h1>`s are reproduced character-for-character from the
legacy site on purpose.

The governing question for every change: **could this move, rename, or alter an
indexed page?** Ranking damage is silent, delayed, and hard to undo — treat it as
the highest-severity bug class, above ordinary correctness.

**Stack:** Laravel · Blade · MySQL · PHPUnit (not Pest) · Pint · Vite

**CI:** **There is no CI on this repo.** Every gate below is the only gate.
Nothing catches a mistake after you push.

## Branch and PR policy

- Feature branch required; branch off `main` (`git switch -c <type>/<short-name>`).
- PR base: `main` — the only long-lived branch. `git push -u origin <branch>` then `gh pr create --base main`.
- Remote: `github.com/andreifiroiu/azilapranz`
- **`git fetch origin` and confirm `main` is not behind before starting.** If
  `main` and `origin/main` ever diverge again, **stop and ask** how to reconcile
  rather than improvising — the two were unrelated histories once already, and
  the fix (a force-push of local over remote, 2026-08-15) discarded a commit.

## Format

`vendor/bin/pint --dirty` — never `--test`.

## Static analysis and frontend gates

If the change touches Blade or CSS, run `npm run build` and look at the page.
`/public/build` is gitignored, so built assets are never committed.

## SEO gates — run before the ordinary tests

Say in the report which you ran and which you skipped, and why.

**Always:**

```
php artisan test --filter=LegacyUrlTest     # status-code + title/canonical matrix
```

**If the change touches slug generation, `config/azp.php` cities, the import, or
venue routing — also:**

```
php artisan test --filter=LegacySlugTest
php artisan azp:verify-urls                 # every live venue URL still resolves
```

`azp:verify-urls` scrapes the still-live legacy site over the network, so it
needs `azilapranz.ro` reachable. It must report **zero** URLs that cannot be
generated. Any non-zero result is a hard stop.

**If the change touches titles, meta, or headings**, diff a sample of rendered
pages against the live legacy site and confirm every difference is intended. Two
are already intentional and expected — `Cluj Napoca` (the legacy rendered
`Cluj-napoca` from an `ucwords()` bug that also left its listing empty) and the
map pages' unique titles (the legacy leaked the homepage's `meta_title`).

## Tests

- Runner: **PHPUnit, not Pest.** Feature tests hit real routes; unit tests cover
  `app/Support/`.
- Affected: `php artisan test --filter=<Name>` or `php artisan test tests/Feature/<Path>`
- Full suite: `composer test` — it does `config:clear` first, which avoids a
  stale-config false pass. **Run it before declaring green.** A test that passes
  alone but fails in the suite is a real isolation bug, not a flake.

## Records

None. This project has no changelog file and no public-changelog skill; the PR
body is the record. Don't invent one unless asked.

## Commit style

- Conventional Commits, via the **`git-commit`** skill.
- Only commit already-staged changes.
- Footer: **no promotional footer.**

## Required PR-body sections

Beyond the standard summary + "Adversarial review", the PR body **must** carry an
**"SEO impact"** section — either *"No indexed URL, title or status code
changes"*, or an explicit list of what moved and why. Write it before creating the
PR, not after.

## After the PR

Nothing automated.

## What breaks in this codebase

**SEO / URL integrity (highest severity)**

- **Route ordering in `routes/web.php`.** `/{city}/{slug}.html` is a catch-all
  that will swallow any more specific `/{city}/...` route registered after it.
  Specific patterns must come first.
- **Any change to a `<title>`, `<meta description>`, `<h1>`, or `rel=canonical`
  expression.** These are reproduced from the legacy controllers deliberately —
  changing one is a ranking decision, not a copy edit. It must be intentional and
  called out in the PR.
- **Redirect status codes.** Legacy URLs must 301, never 302. Suspended venues
  must 410, not 404 and not a soft 200.
- **New routes shadowing the static-file verification endpoints**
  (`/google619c7d2c3f8ef1df.html`, `/eb067c8d04cd.html`) or `robots.txt` /
  `sitemap.xml`.
- Anything that drops a URL family without a redirect.

**Eloquent traps that have bitten here before**

- **Accessor shadowing a column.** `getUrlAttribute()` silently hid the venue's
  own `url` column. Before naming an accessor, check no column shares the name.
- **Column names colliding with Eloquent internals** — the legacy `attributes`
  column had to be imported as `services`.
- **N+1 on the listing pages** — a city page renders up to 186 venues. Check the
  Debugbar query count; the city listing should stay in single digits.

**Legacy data reality — never assume clean input**

- `type`, `specific`, `services`, `area` are comma-separated multi-value strings.
- Text may contain U+00A0, HTML entities, and Word paste residue.
- Any legacy HTML rendered with `{!! !!}` **must** go through `LegacyHtml::clean()`
  first. Rendering `description` or `pages.content` raw is a security bug — one
  CMS page carries third-party `<script>` tags.

**Blade**

- Escaped quotes inside a `:prop="[...]"` array attribute do not parse. Build the
  array in an `@php` block and pass the variable.

## Frozen surfaces

Changing these needs an explicit reason in the PR body:

- **`app/Support/LegacySlug.php`** — a verbatim port of the legacy
  `URL::encodeUrlTerm()`, quirks included (single-pass dash collapse, Romanian
  diacritics falling through to `-`). It is pinned to every indexed URL. **Do not
  "clean it up".** `Str::slug()` is not a substitute.
- **`config/azp.php` `cities`** — the routable slug set. Adding a city creates
  URLs; removing one destroys them.
- **`database/legacy-corrections.php`** — hand-retyped text repairing diacritics
  the legacy destroyed.

## Deployment reminders — flag, don't do

Not part of shipping a PR, but mention them if a change gets close to cutover;
they live outside Laravel and are still outstanding:

- `m.azilapranz.ro` still 302s every mobile UA and **drops the path**. Needs a
  wildcard `301 m.azilapranz.ro/* → /$1`.
- Apex and `www` both serve 200 with no canonicalisation. One must 301 to the
  other.
- **`AZP_ANALYTICS_ID`** — production runs on the default in `config/azp.php`,
  so nothing is needed there today. But it is the master switch for both the
  GA4 tag and the cookie banner: if a `.env` ever sets it empty, measurement
  and the consent bar both vanish with no error. Any change to it needs
  `php artisan config:cache` in the *same* deploy, and
  `curl -s https://azilapranz.ro/ | grep -c googletagmanager` (expect `1`)
  afterwards.
