---
title: Presentation Register for prose-fix - Plan
type: feat
date: 2026-11-16
topic: prose-fix-presentation-register
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-brainstorm
execution: code
---

# Presentation Register for prose-fix - Plan

## Goal Capsule

- **Objective:** A visitor reading the opening of a Bånder website page learns what a feature does for them and wants to try it, and every claim they read holds for what Bånder ships today.
- **Means:** A presentation register in the repository's prose-fix skill, built from Steve Jobs's product presentations, that rewrites and drafts introductions from cited sources; the homepage hero is its first use (R1, R17).
- **Product authority:** This Product Contract. prose-fix's existing preservation rules and the Chen, Knuth and Stroustrup register assignments stay in force wherever this contract does not name the new register.
- **Execution profile:** Agent skill files under `.agents/skills/prose-fix/`, the homepage template, and the homepage's search and link-preview copies (A1); no application code, schema or API change.
- **Finish:** `ce-work` on a branch named after this plan, with units as local commits; push only when asked.
- **Stop conditions:** Stop and ask if the recordings' caption tracks cannot be read (R2, KTD2), or if the hero cannot be made inviting without a claim its sources do not support (R7).
- **Open blockers:** None.

---

## Product Contract

### Summary

prose-fix gains a fourth register, named after Steve Jobs, for presenting a feature: what it does, technical detail made easy to follow, and a reason to try it. The register rewrites existing introductions and drafts new ones, and each claim it adds cites shipped code, a maintained doc or a recorded measurement. Its first use rewrites the homepage hero.

### Problem Frame

The homepage hero reads "Browse your music and movies in Bånder. A media server you run on your own hardware." It lists properties, like a spec sheet. The page below it shows what a listener can do: move playback to another device and keep listening, shape the sound with an equalizer and keep one profile for headphones and another for speakers, build smart playlists. The opening never says why a listener would care.

prose-fix cannot repair this today. The house style has no row for the website templates, so the skill picks a register by purpose. The nearest fit is Chen, which the house style assigns to the README and the docs-book introductions, and its checklist asks for "zero adjectives of enthusiasm". The skill's rewrite rules remove unsupported praise. Its description excludes marketing copy. It only rewrites, and its preservation audit reports every number a rewrite adds. An agent asked to make an introduction inviting either produces another spec sheet or improvises a pitch with nothing checking its claims.

The project also has a record to protect. Bånder is pre-release, casting to AirPlay, Chromecast and DLNA is planned rather than available, and the Bånder Audio Core page states where its codec trails the reference encoders. Copy that excites by overstating would break that record.

### Key Decisions

- **Extend prose-fix to draft as well as rewrite.** An inviting introduction needs facts the current text does not carry. (session-settled: user-approved — chosen over a rewrite-only register and over a separate drafting skill: the user picked rewrite and draft inside prose-fix with the claim-source check shown.) Governs R14, R15, R16.
- **Move limits out of the lead, never drop them.** The lead gets room to show what works while the page keeps every limit. (session-settled: user-approved — chosen over keeping limits inline and over linking them out to another page: the held-out recordings disclosure on the Bånder Audio Core page is the precedent.) Governs R11, R12, R13.
- **Ship the homepage hero in this work.** The hero is the register's first real test on the page that prompted it. (session-settled: user-approved — chosen over a skill-only change that uses the hero only as a worked example.) Governs R17, R18, R19.
- **Narrow default mapping.** `AGENTS.md` runs prose-fix on every document an agent writes, so a purpose-based default would put pitch copy into routine documentation. Governs R5.
- **Keep the presentation techniques and drop the keynote habits that read as filler.** Superlatives, reveals and reversals work on a stage but match the patterns that make written copy read as filler; the excitement comes from choosing the most striking true fact and saying what it means for the listener. Governs R3, R4.
- **Name the register after Jobs.** The skill names its registers after authors, and the user asked for a Jobs persona. Governs R1, R2.

### Requirements

**The register and its sources**

- R1. prose-fix offers a Jobs register for presenting a feature: introducing what it does, explaining its technical detail in plain words, and giving the reader a reason to try it.
- R2. The register sheet is built from Jobs's product presentations and speeches read during implementation, lists the sources read, and quotes only text verified against a transcript or recording.
- R3. The sheet teaches the techniques that work in writing: open from the listener's moment or problem, present one idea at a time, show the feature working, turn a specification into what the listener gets, and explain technical detail in plain words.
- R4. The sheet excludes superlatives and enthusiasm adjectives, "one more thing" reveals, "it's not X, it's Y" reversals, rows of dramatic fragments, triads padded to three items, and closing outlooks about the future.

**Where the register applies**

- R5. The register is the default only for the opening sections of page copy written in the website templates, today the homepage and the Bånder Audio Core page; any other text, including the manual pages the site renders from `docs-book/`, gets it only when a request names it.
- R6. prose-fix's description admits feature presentation on site pages and in documentation, while social posts, launch announcements and other channel copy stay with the ce-promote skill.

**Grounding claims**

- R7. Every factual claim the register adds is supported by shipped code, a maintained doc or a recorded measurement, and the delivery report names that source.
- R8. A translated figure, a specification restated as a listener-facing quantity, is allowed only when it can be computed from a cited source fact, and the report shows the computation.
- R9. Planned or in-development features are never presented as available.
- R10. Claims about what Bånder does well cite shipped behavior or recorded results; presentation copy names no competing product, and the competitor table in `STRATEGY.md` is not a source for it.

**Limits and caveats**

- R11. A rewrite may move a limit out of the lead into a labeled section or disclosure on the same page, where it keeps its original strength.
- R12. A qualifier without which the lead claim would be false stays beside that claim.
- R13. A drafted introduction carries the documented limits of the features it leads with.

**Rewriting and drafting**

- R14. The register rewrites existing introductions and drafts new ones from code, docs and recorded measurements.
- R15. A rewrite still runs prose-fix's preservation audit, and each claim or number the audit reports as added is accounted for under R7 or R8.
- R16. A draft, which has no baseline to audit against, is checked claim by claim against its source list.

**Homepage hero**

- R17. The homepage hero's eyebrow line above the headline, the headline and the description are rewritten in the register, keeping any line that already works.
- R18. The hero keeps its calls to action, its pre-release note and its screenshot.
- R19. The website build and its browser tests pass after the hero change.

### Acceptance Examples

- AE1. **Covers R9, R13.** **Given** the homepage lists AirPlay, Chromecast and DLNA casting as planned, **when** the register writes a lead about moving playback between devices, **then** the lead describes moving playback only between devices signed in to Bånder, and the casting note stays on the page as planned.
- AE2. **Covers R11, R12.** **Given** the Bånder Audio Core page says the server transcoder uses the codec "by default" for AAC renditions, **when** the register rewrites that lead, **then** "by default" stays beside the claim, and the note that the Electron integration does not make the codec the web app's default playback path may move to a labeled limits section on the same page.
- AE3. **Covers R8.** **Given** a recorded encode speed expressed as a multiple of real time, **when** the register writes how much CPU time an hour of music takes to encode, **then** the report cites the measurement and shows 3,600 seconds divided by that multiple.
- AE4. **Covers R10.** **Given** a request to introduce Bånder Audio Core, **when** the register drafts the lead, **then** the lead names no competing product, and the page's measured comparisons, including the low-rate results where Bånder Audio Core trails the references, stay in the measurement sections.
- AE5. **Covers R5.** **Given** an agent edits an operator-guide introduction in `docs-book/` and runs prose-fix as `AGENTS.md` requires, **when** prose-fix chooses a register, **then** it uses Chen unless the request names the Jobs register.

### Success Criteria

- A reader who has never heard of Bånder can say from the new hero what they would do with it, and the user judges the hero inviting rather than a spec sheet.
- Copy written in the register shows no findings in a ce-noslop detect pass beyond that skill's false-positive floor.

### Scope Boundaries

**Deferred for later**

- Rewriting the Bånder Audio Core page's lead, the `README.md` opening or the docs-book introductions; each can follow on request. R5's default mapping covers the Bånder Audio Core lead from this work on, but this work rewrites only the homepage hero, and AE2 and AE4 illustrate rules rather than deliver a rewrite.
- A release-notes or what's-new document; none exists today.

**Outside this work**

- Social posts, launch announcements and other channel copy, which remain with the ce-promote skill.
- Changes to the ce-noslop or ce-promote plugin skills.
- Spoken keynote devices with no written counterpart, such as props, pauses and live demos, beyond the screenshots the pages already show.
- Copy that positions Bånder against named products.

### Dependencies / Assumptions

- The recordings of the chosen Jobs presentations, and their caption tracks, can be read during implementation (KTD2, A2).
- No browser test asserts the hero's wording. `ui/site/tests/stability.spec.ts` measures `.hero-copy :is(h1, p)` as layout blocks, so the hero keeps a headline and paragraphs.

### Sources / Research

- `.agents/skills/prose-fix/SKILL.md:3` (description excluding marketing copy), `:29-36` (register choice by purpose), `:61` (remove unsupported praise); `scripts/audit_rewrite.php` reports numbers gained and lost.
- `.agents/skills/prose-fix/references/project-house-style.md:15` maps `README.md` and operator-guide introductions to Chen; `:18` is the Mixed row; `:42-44` keeps claims at their original strength.
- `.agents/skills/prose-fix/references/chen.md:164` asks for zero adjectives of enthusiasm. Each existing register sheet names the sources read for it (`chen.md:7`, `knuth.md:7`, `stroustrup.md:7`).
- `AGENTS.md:24` runs prose-fix on documentation prose agents write or change; `AGENTS.md:96-97` keeps skill edits under `.agents/`.
- `ui/site/templates/home.php:8-15` is the hero, with the pre-release note at `:15`; `:168` lists casting as planned; the feature sections with screenshots cover the equalizer and input analyzer, A/B comparison and device profiles, smart and shared playlists, and playback destinations.
- `ui/site/templates/bac.php:53`, `:71-72` and `:215` keep limits beside claims; commit 6b2c4a9de moved the held-out recordings text into a disclosure without changing it.
- `ui/DESIGN.md` and `.agents/rules/frontend.md` set typography and layout rules but no voice for site copy; neither conflicts with R5 or R17.
- `STRATEGY.md:27-33` (competitor table) and `:160-168` (product principles).
- Website build and tests: `make website-build`, `corepack yarn test:browser` in `ui/site/`, and `.github/workflows/website.yaml`.
- ce-noslop's pattern catalog (plugin skill) names the filler patterns R4 excludes: rules 3, 5, 6, 12, 13 and 27.
- The hero's copies: `packages/baander-tooling/src/Website/SearchMetadata.php` (`HOME_TITLE`, `HOME_DESCRIPTION`), `ui/site/scripts/social-card.mjs` (card headline and description), `ui/site/templates/head.php` (`og:image:alt`), `ui/site/templates/footer.php` (tagline).
- Transcript research, 2026-11-16: the only near-complete transcript found is the third-party Singju Post text of the 2007 iPhone keynote, with visible errors; the 2001 iPod and 2008 MacBook Air events have only press paraphrase; Stanford's 2005 address text is the prepared version.

---

## Planning Contract

Product Contract preservation: restructured, no scope change. The three Deferred to Planning questions are answered by KTD2, KTD3 and KTD4, and the Outstanding Questions section is removed. The transcript dependency now names the caption route (KTD2).

### Key Technical Decisions

- KTD1. **The Jobs sheet follows the existing register anatomy.** A lead paragraph names the sources read, an "Assign Jobs to" line states its surfaces, numbered principles carry verified quotes as evidence, a before/after section rewrites Baander copy, and a checklist closes it, as in `references/chen.md` and `references/stroustrup.md`. A reader who knows one sheet can use the new one. Governs R1, R2, R3, R4.
- KTD2. **Quotes are verified against the recordings' caption tracks.** The sheet draws on the 2007 iPhone, 2001 iPod and 2008 MacBook Air introductions. Third-party transcripts only help locate a line; the caption track of a recording is the check. Research found one near-complete transcript, for the iPhone keynote, which is unofficial and contains errors, and none for the iPod or MacBook Air events. The 2005 Stanford address is not a source: it is storytelling rather than feature presentation, and Stanford's text is the version as prepared, not as spoken. A line whose caption is ambiguous is left out rather than quoted. Governs R2.
- KTD3. **The claim check is a claim ledger, not a script.** Every rewrite and draft in the Jobs register produces a ledger: each claim it adds or keeps in the lead, its source, and the arithmetic of any translated figure. `audit_rewrite.php` keeps reporting gained numbers and code tokens, and the ledger accounts for each one. A script cannot judge whether a source supports a claim, and the audit misses a new claim that carries no number. The ledger goes into the commit message of the change it covers, so the evidence stays tracked. Governs R7, R8, R15, R16.
- KTD4. **The mapping is a house-style row plus a guarded purpose row.** `references/project-house-style.md` gains a Jobs row naming the hero of `ui/site/templates/home.php` and the lead of `ui/site/templates/bac.php`, where the lead is the copy before the page's first section heading. The row also states that manual pages rendered from `docs-book/` keep their existing rows. In `SKILL.md`, the choose-by-purpose table gains a Jobs row whose purpose cell limits it to mapped surfaces and named requests, so an unmapped document never falls to Jobs by purpose. The house style keeps its precedence over all register sheets, including its rule on claim strength. Governs R5, AE5.
- KTD5. **Drafting is a Jobs-register mode only.** Chen, Knuth and Stroustrup stay rewrite-only. `SKILL.md` gains a draft request beside its rewrite and review requests, and its description names feature presentation while excluding fiction and channel copy. Governs R6, R14.
- KTD6. **Availability claims cite maintained documentation.** In every Jobs-register rewrite or draft, a claim that a feature is available cites an operator-guide or developer-guide page, with code as corroboration. Code alone does not establish availability, because groundwork for planned features, such as the movie and concert code, also ships. Other homepage sections are copy, not sources. Governs R7, R9.

### Assumptions

- A1. The hero's copies follow the new hero: the homepage search title and description in `packages/baander-tooling/src/Website/SearchMetadata.php`, the headline and description in `ui/site/scripts/social-card.mjs`, the committed `ui/site/assets/social-card.png` it renders, and the `og:image:alt` text in `ui/site/templates/head.php`. Left alone, link previews would show a headline the page no longer has. A copy of a hero line that did not change stays as it is. The footer tagline "A media server you host." stays unless the new hero contradicts it.
- A2. The locally installed `yt-dlp` can fetch the three recordings' caption tracks. If the session's permission rules block the download, the stop condition applies: implementation stops and asks the user to approve it or supply transcripts.

### Sequencing

U1, then U2, then U3, then U4. U2 cites the sheet U1 writes, U3 runs the wired skill, and U4 copies the text U3 settles.

### Risks

- **The new wording can fail the stability check.** `ui/site/tests/stability.spec.ts` requires every `.hero-copy` headline and paragraph to keep the same height with the fallback font as with Geist, at 1440 px and 390 px. A line that wraps differently in the two fonts fails it. Run the browser suite on each candidate hero before asking the user to judge it, and change the wording rather than the CSS.
- **Auto-generated captions contain errors.** KTD2 leaves out any quote whose caption is unclear; the sheet can teach a technique without a quote when no clean line exists.

---

## Implementation Units

### U1. Write the Jobs register sheet

- **Goal:** A register sheet that teaches feature presentation, with every quote verified against a recording.
- **Requirements:** R1, R2, R3, R4.
- **Dependencies:** None.
- **Files:** Create `.agents/skills/prose-fix/references/jobs.md`.
- **Approach:**
  1. Fetch the caption tracks of the three recordings (KTD2, A2) into the session scratchpad, never into the repository.
  2. Collect candidate lines for each R3 technique, using the Singju Post iPhone transcript only to find them, and keep a line only when its caption matches.
  3. Write the sheet in the KTD1 anatomy. List each source with its recording URL, and give each quote a timestamp so a later reader can check it.
  4. Write a section on R4's excluded devices that names the permitted alternative for each one.
  5. Write the before/after examples from homepage feature-section copy, such as the playback destinations section, never from the hero U3 rewrites. Cite each "after" claim's source per KTD6.
- **Patterns to follow:** `.agents/skills/prose-fix/references/chen.md` and `references/stroustrup.md`.
- **Test expectation:** none. This unit is reference prose; Verification checks it.
- **Verification:**
  - Each quote matches its caption track at the stated timestamp.
  - The before/after "after" texts show no ce-noslop detect findings beyond its false-positive floor.
  - The checklist covers R3 and R4.

### U2. Wire the register into prose-fix

- **Goal:** prose-fix chooses, applies and checks the Jobs register as R5 to R16 require.
- **Requirements:** R5 to R16, AE1 to AE5.
- **Dependencies:** U1.
- **Files:** Modify `.agents/skills/prose-fix/SKILL.md` and `.agents/skills/prose-fix/references/project-house-style.md`.
- **Approach:**
  1. Update the frontmatter description per KTD5.
  2. Add the draft request to "Scope and context" (KTD5).
  3. Add the Jobs row to the "Choose the register" table, and the Jobs row and its boundary notes to the house style (KTD4). The boundary notes say the `STRATEGY.md` competitor table is not a source (R10) and that measured comparisons stay on measurement pages.
  4. Add the presentation rules: claim sources (R7, KTD6), translated figures (R8), planned features (R9), competitors (R10), and moving limits without dropping them (R11 to R13).
  5. In "Rewrite", explain how the register squares with "remove unsupported praise": its enthusiasm is the choice of a cited fact, never an added adjective.
  6. Add the claim ledger (KTD3) to "Verify preservation" and "Deliver".
- **Patterns to follow:** The existing Mixed row in `project-house-style.md` for section-scoped registers.
- **Test expectation:** none. These are skill instructions; U3 is the first real use, and Verification walks through the acceptance examples.
- **Verification:**
  - Covers AE5. The updated text sends an operator-guide introduction to Chen, sends the homepage hero to Jobs, and sends a `docs-book/` page to Jobs only when the request names it.
  - Covers AE1 to AE4. The rule each example exercises has one home in `SKILL.md` or the house style.

### U3. Rewrite the homepage hero

- **Goal:** The hero tells a first-time visitor what they would do with Bånder, and every claim is sourced.
- **Requirements:** R17, R18; applies R7, R9 and R13; AE1; first Success Criterion.
- **Dependencies:** U2.
- **Files:** Modify `ui/site/templates/home.php`. Existing coverage: `ui/site/tests/stability.spec.ts`, `ui/site/tests/website.spec.ts`, `ui/site/tests/rendering.spec.ts`, `ui/site/tests/experience.spec.ts`.
- **Approach:**
  1. Run prose-fix in the Jobs register on the eyebrow line, headline and description, with a baseline copy and the preservation audit.
  2. Keep the `.hero-copy` structure of one headline and paragraphs, which the stability check measures, and keep the calls to action, the pre-release note and the screenshot (R18).
  3. Keep the lead consistent with the kept screenshot, which shows the music library and playback controls; the screenshot's alt text and caption describe the image and stay as they are.
  4. Run the site browser suite on each candidate before showing it to the user, per the stability risk.
  5. Write the claim ledger (KTD3) into the commit message.
- **Test scenarios:**
  - At 1440 px and 390 px, the hero headline and description have the same block heights with the fallback font as with Geist (`stability.spec.ts`).
  - The "Start hosting" call to action still leads to the operator guide's getting-started page (`website.spec.ts`).
  - Covers AE1. The hero names no casting receiver, and the casting note still reads as planned.
  - The homepage stays contained, with no horizontal overflow, at every viewport and colour scheme `website.spec.ts` tests.
- **Verification:**
  - The user judges the new hero inviting rather than a spec sheet.
  - Every hero claim appears in the ledger with a source.
  - ce-noslop detect finds nothing beyond its false-positive floor.

### U4. Keep the hero's copies in step

- **Goal:** Search results and link previews show the same headline and description as the page.
- **Requirements:** R17 (A1).
- **Dependencies:** U3.
- **Files:** Modify `packages/baander-tooling/src/Website/SearchMetadata.php`, `ui/site/scripts/social-card.mjs` and `ui/site/templates/head.php`. Regenerate `ui/site/assets/social-card.png`. Test: `scripts/tests/php/Website/SearchMetadataTest.php`.
- **Approach:**
  1. Update only the copies of hero lines that U3 changed (A1).
  2. Keep the homepage title within the 60-character limit and the description within the 155-character limit that `SearchMetadata` applies to other pages.
  3. Regenerate the card after a site build with `ui/site/scripts/social-card.mjs`, and check it at full size to confirm the headline and description fit their boxes.
  4. Run `.php-cs-fixer.tooling.php` on the touched PHP files.
- **Patterns to follow:** The card regeneration note in `ui/site/README.md`.
- **Test scenarios:**
  - The homepage metadata title is at most 60 characters and its description at most 155 (new assertions in `SearchMetadataTest`).
  - The homepage metadata still describes a `WebSite` and a `SoftwareApplication` at `https://baander.app/` (existing test).
  - The `og:image:alt` text quotes the headline the regenerated card shows.
- **Verification:** A built homepage's description and Open Graph tags carry the new copy, and the committed card shows the new headline without overflow.

---

## Verification Contract

| Gate | Command | Applies to |
|---|---|---|
| Preservation audit | `php .agents/skills/prose-fix/scripts/audit_rewrite.php <baseline> <edited>`, with every delta accounted for in the claim ledger | U3 |
| Website build | `make website-build` | U3, U4 |
| Site script tests | `node --test scripts/*.test.mjs` in `ui/site/` | U4 |
| Site browser suite | `corepack yarn test:browser` in `ui/site/` | U3, U4 |
| Tooling tests | `composer run-script --timeout=7200 test:tooling`, per the [testing guide](../../docs-book/part-2-developer-guide/testing/README.md) | U4 |
| Tooling static analysis | `composer analyse:tooling` | U4 |
| Writing check | ce-noslop detect on the sheet's examples and the hero copy | U1, U3 |
| Whitespace | `git diff --check` | All |

## Definition of Done

- Every unit's verification holds and the gates above pass; any gate that could not run is reported with the reason.
- The sheet's quotes each match a caption track, and no quote comes from memory or an unchecked transcript.
- The hero's claim ledger is in its commit message, and the user has judged the hero.
- `ROADMAP.md` records the delivered item with its verification checkpoint (suite counts, static gates).
- No code, comment or test cites plan IDs.
- Abandoned drafts, scratch captions and debugging aids are out of the diff.
