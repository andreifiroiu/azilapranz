---
name: ship-it
description: Finalize an AziLaPranz change end-to-end — adversarial review of the whole feature, SEO/URL parity gates, format, tests, conventional commit, and open a PR. Auto-invoke when the user says "ship it", "wrap this up", "finalize", "ready to commit/PR", "open a PR for this", or otherwise signals a feature/fix is done and should go out. Orchestrates the project's definition-of-done; do not skip steps.
---

# Ship it — AziLaPranz definition of done

Take the current change from "code written" to "PR opened". Work top to bottom.
If a step surfaces a real problem, **stop and report it** rather than pushing
broken work.

**There is no CI on this repo.** Every gate below is the only gate. Nothing
catches a mistake after you push.

## What this project is

A Laravel rebuild of a 2011-era PHP restaurant directory, kept alive almost
entirely for its **search rankings**. 552 venue pages across 7 cities, whose
URLs, `<title>`s and `<h1>`s are reproduced character-for-character from the
legacy site on purpose.

So the governing question for every change is: **could this move, rename, or
alter an indexed page?** Ranking damage is silent, delayed, and hard to undo —
treat it as the highest-severity bug class, above ordinary correctness.

## 0. Scope the change

- Make sure you're on a feature branch, not `main`. If on `main`, branch first
  (`git switch -c <type>/<short-name>`).
- `git fetch origin` and confirm `main` is not behind. If `main` and
  `origin/main` ever diverge again, **stop and ask** how to reconcile rather
  than improvising — the two were unrelated histories once already, and the
  fix (a force-push of local over remote, 2026-08-15) discarded a commit.
- Determine the **full feature diff**, not just the last edit:
  `git diff main...HEAD` plus staged/unstaged/untracked changes. Everything
  below reviews and ships the whole change.

## 1. Adversarial review of the entire change

Review the complete diff with an **adversarial mindset — try to break it**, not
to praise it. Prefer launching review subagents in parallel over the full diff
(e.g. `pr-review-toolkit:code-reviewer` and
`pr-review-toolkit:silent-failure-hunter`, or the `/code-review` skill at high
effort); consolidate the findings — they go in the PR body.

Hunt specifically for the failure modes this codebase actually has:

**SEO / URL integrity (highest severity)**
- Route **ordering** in `routes/web.php`. `/{city}/{slug}.html` is a catch-all
  that will swallow any more specific `/{city}/...` route registered after it.
  Specific patterns must come first.
- Any change to a `<title>`, `<meta description>`, `<h1>`, or `rel=canonical`
  expression. These are reproduced from the legacy controllers deliberately —
  changing one is a ranking decision, not a copy edit. It needs to be intentional
  and called out in the PR.
- Redirect **status codes**. Legacy URLs must 301, never 302. Suspended venues
  must 410, not 404 and not a soft 200.
- New routes that could shadow the static-file verification endpoints
  (`/google619c7d2c3f8ef1df.html`, `/eb067c8d04cd.html`) or `robots.txt` /
  `sitemap.xml`.
- Anything that drops a URL family without a redirect.

**Frozen surfaces** — changing these needs an explicit reason in the PR body:
- `app/Support/LegacySlug.php` — a verbatim port of the legacy
  `URL::encodeUrlTerm()`, quirks included (single-pass dash collapse, Romanian
  diacritics falling through to `-`). It is pinned to every indexed URL. **Do
  not "clean it up".** `Str::slug()` is not a substitute.
- `config/azp.php` `cities` — the routable slug set. Adding a city creates URLs;
  removing one destroys them.
- `database/legacy-corrections.php` — hand-retyped text repairing diacritics the
  legacy destroyed.

**Eloquent traps that have bitten here before**
- **Accessor shadowing a column.** `getUrlAttribute()` silently hid the venue's
  own `url` column. Before naming an accessor, check no column shares the name.
- **Column names colliding with Eloquent internals** — the legacy `attributes`
  column had to be imported as `services`.
- **N+1** on the listing pages (a city page renders up to 186 venues). Check the
  Debugbar query count; the city listing should stay in single digits.

**Legacy data reality** — never assume clean input:
- `type`, `specific`, `services`, `area` are comma-separated multi-value strings.
- Text may contain U+00A0, HTML entities, and Word paste residue.
- Any legacy HTML rendered with `{!! !!}` **must** go through
  `LegacyHtml::clean()` first. Rendering `description` or `pages.content` raw is
  a security bug — one CMS page carries third-party `<script>` tags.

**Blade**
- Escaped quotes inside a `:prop="[...]"` array attribute do not parse. Build
  the array in an `@php` block and pass the variable.

**General**
- Silent failures — swallowed exceptions, empty catches, fallbacks that hide a
  real error.
- Test gaps — happy path + failure path + a weird path.

Fix blocking issues, or surface them clearly if they need a decision. Re-review
if you changed anything substantive.

## 2. SEO gates

Run the gates the change actually warrants — say in the report which you ran and
which you skipped, and why.

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
pages against the live legacy site and confirm every difference is one you
intended. Two are already intentional and expected — `Cluj Napoca` (the legacy
rendered `Cluj-napoca` from an `ucwords()` bug that also left its listing empty)
and the map pages' unique titles (the legacy leaked the homepage's `meta_title`).

**If the change touches Blade or CSS**, run `npm run build` and look at the page.
`/public/build` is gitignored, so built assets are never committed.

## 3. Format

Run `vendor/bin/pint --dirty` (never `--test`). Let it fix style.

## 4. Tests

- Run the affected tests by filter or path, e.g.
  `php artisan test --filter=<Name>` or `php artisan test tests/Feature/<Path>`.
- If nothing covers the change, **write a test first** (PHPUnit, not Pest), then
  run it. Feature tests hit real routes; unit tests cover `app/Support/`.
- **All targeted tests must pass.** If any fail, stop and report the output — do
  not commit.
- Before declaring green, run the full suite once with `composer test` — it does
  `config:clear` first, which avoids a stale-config false pass. A test that
  passes alone but fails in the suite is a real isolation bug, not a flake.

## 5. Commit

Invoke the **`git-commit`** skill (Conventional Commits). Only commit
already-staged changes; don't `git add` for the user unless they ask. **No
promotional footer.**

## 6. Open the PR

- Push the branch (`git push -u origin <branch>`).
- Create the PR with `gh pr create --base main`. `main` is the only long-lived
  branch; remote is `github.com/andreifiroiu/azilapranz`.
- **PR body**: a short summary, an **"Adversarial review"** section with the
  consolidated findings from step 1 (what was checked, what was fixed, residual
  risks), and an **"SEO impact"** section — either *"No indexed URL, title or
  status code changes"*, or an explicit list of what moved and why. Keep it
  clean, no promotional footer.
- Return the PR URL.

This project has no changelog file and no public-changelog skill; the PR body is
the record. Don't invent one unless asked.

## Output

A short report: review verdict (issues found/fixed), which SEO gates ran and
their results, pint result, test counts, commit hash/subject, and the PR URL.

## Deployment reminders (flag, don't do)

Not part of shipping a PR, but mention them if a change gets close to cutover —
they live outside Laravel and are still outstanding:
- `m.azilapranz.ro` still 302s every mobile UA and **drops the path**. Needs a
  wildcard `301 m.azilapranz.ro/* → /$1`.
- Apex and `www` both serve 200 with no canonicalisation. One must 301 to the
  other.
