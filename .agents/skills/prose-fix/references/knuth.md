# Register: Donald Knuth (The Art of Computer Programming)

Knuth writes procedures meant to be executed exactly, by a reader he respects as a
peer, and he takes personal, named responsibility for everything on the page —
including his own failures, which he exhibits as warnings. The prose is formal but
warm: precise quantifiers, labeled steps, scholarly citations, aphoristic morals, and a
sense of humor that lives in the structure itself. Sources read for this sheet: the
Preface to the Second Edition of Volume 2, Chapter 3 "Random Numbers" (especially
§3.1, including the Algorithm K story), and Chapter 4 "Arithmetic" (especially §4.1).

**Assign Knuth to:** runbooks, ceremonies, bootstrap/genesis procedures, inventories,
drills — documents whose steps must be executed in order, where skipping one is
catastrophic, and where the document itself is part of the procedure's correctness.

## The principles, with the source in evidence

### 1. Procedures are named, and their steps are labeled with intent

> "Algorithm K ("Super-random" number generator). Given a 10-digit decimal number X,
> this algorithm may be used to change X to the number that should come next in a
> supposedly random sequence. Although the algorithm might be expected to yield quite
> a random sequence, reasons given below show that it is, in fact, not very good at
> all."

> "K1. [Choose number of iterations.] Set Y ← ⌊X/10⁹⌋, the most significant digit of
> X. (We will execute steps K2 through K13 exactly Y + 1 times; that is, we will apply
> randomizing transformations a random number of times.)"

The pattern: a formal name, a one-sentence contract ("Given … this algorithm …"),
an honesty note up front, then steps labeled K1, K2, … each with a bracketed statement
of *intent* followed by the exact operation, and parenthetical precision about
quantities ("exactly Y + 1 times"). In prose documents the same shape appears as
numbered steps with a named purpose — never an anonymous bullet list of actions.

### 2. Take first-person ownership, especially of failures

The most instructive passage in Volume 2 is Knuth exhibiting his own bad algorithm:

> "It is not easy to invent a foolproof source of random numbers. This fact was
> convincingly impressed upon the author several years ago, when he attempted to
> create a fantastically good generator using the following peculiar approach: …"

> "Considering all the contortions of Algorithm K, doesn't it seem plausible that it
> should produce almost an infinite supply of unbelievably random numbers? No! In
> fact, when this algorithm was first put onto a computer, it almost immediately
> converged to the 10-digit value 6065038420, which — by extraordinary coincidence —
> is transformed into itself by the algorithm."

And in the Preface:

> "I suppose some mistakes still remain, or have crept in, and I would like to fix
> them; therefore I will cheerfully pay $2.00 reward to the first finder of each
> technical, typographical, or historical error."

The author is a character in the text: fallible, named, accountable. A runbook in this
register says who did the thing, what went wrong when it was done, and what the reader
should therefore watch.

### 3. State the moral as an aphorism

> "The moral of this story is that random numbers should not be generated with a
> method chosen at random. Some theory should be used."

One sentence, balanced, quotable, placed immediately after the evidence. A Knuth
document earns its aphorisms with a story first — the aphorism without the story is a
slogan; with the story, it is a lesson. (Chapter epigraphs — von Neumann's "in a state
of sin", Mrs. La Touche's "I do hate sums" — are the same instinct: the chapter's
thesis stated by a voice with personality.)

### 4. Quantify precisely or say the guess is a guess

> "The exact date when this notation first appeared is quite uncertain; about 600 A.D.
> seems to be a good guess."

> "I estimate that about 45 percent of the book has changed."

> "Metropolis obtained a sequence of about 750,000 numbers before degeneracy occurred,
> and the resulting 750,000 × 38 bits satisfactorily passed statistical tests for
> randomness. This shows that the middle-square method can give usable results, but it
> is rather dangerous to put much faith in it until after elaborate computations have
> been performed."

Numbers are exact when known, explicitly approximate when not ("about", "seems to be a
good guess"), and conclusions are drawn at exactly the strength the evidence supports
("can give usable results, but it is rather dangerous to put much faith in it").
Never round a guess into a fact.

### 5. Scholarship: origins are traced and cited

§4.1 does not assert that positional notation is old; it walks the transmission —
Hindu manuscripts written backwards, al-Khwārizmī, Fibonacci's sexagesimal fraction
"1° 22′ 7″ 42‴ 33⁗ 4⁗ 40⁗" — and names the historians. Applied to a runbook: every
step states *where it comes from* (which spec section, which ceremony, which prior
document), because an untraced step cannot be audited. Cross-references are the
prose equivalent of citations.

### 6. Humor is structural, not decorative

Knuth's jokes are the material itself, framed so the reader discovers them: the
"super-random" generator that collapses into a fixed point of itself; the
machine-language program "intended to be so complicated that a person reading a
listing of it without explanatory comments wouldn't know what the program was doing"
— followed by its immediate self-destruction. The humor of a *runbook* works the same
way: name the failure mode so vividly ("The archive makes it a bad week, not a
death.") that the reader remembers it at 3 a.m.

### 7. Enumerations are parallel and exhaustive at their level

> "a) Simulation. When a computer is being used to simulate natural phenomena…
> b) Sampling. It is often impractical to examine all possible cases…
> c) Numerical analysis. Ingenious techniques…
> d) Computer programming. Random values make a good source of data…"

Lettered or numbered items with identical grammatical shape, each complete in one or
two sentences, collectively covering the claim being made. Ragged lists — mixed
shapes, overlapping items — are rewritten until they march.

### 8. Drill what must be true

Knuth assigns exercises because a procedure's result matters as much as its
explanation. When the source records a drill, verification step, or failed attempt,
keep it visible. Do not invent a test result, drill schedule, or recovery guarantee
to imitate this register.

### 9. Scope honesty, stated where the reader will trip over it

> "…it seems reasonable to believe that very few of the topics discussed here will
> ever become obsolete."

> "In this chapter, we shall consider random number generators that are superior to
> the middle-square method and to Algorithm K; the corresponding sequences are
> guaranteed to have certain desirable random properties, and no degeneracy will
> occur."

Say exactly what the chapter will and will not establish, and let guarantees be
guarantees only where they are guaranteed.

## Before / after (Baander documentation → Knuth register)

These examples illustrate wording, not additional operational requirements. Preserve
the actual procedure and its guarantees when applying the register.

**Before:** "It is important to run the update commands in the right order every
time you pull changes."
**After:** "Run the update commands in order every time you pull changes."

**Before:** "In cases where you only need the main database to be migrated, such as
on a production server without a test database, there is an alternative command."
**After:** "If you only need to migrate the main database, use `make migrate-no-test`.
This applies, for example, to a production server without a test database."

## Knuth checklist

- [ ] Every procedure has a name and a one-sentence contract; steps carry intent
      labels, not just actions.
- [ ] Quantities are exact, or explicitly approximate ("about", "a good guess").
- [ ] The author is present and accountable; failures are exhibited, not hidden.
- [ ] Each aphorism is earned by the story before it.
- [ ] Lists are parallel in grammar and exhaustive at their stated level.
- [ ] Every step cites its origin (section, ceremony, prior document).
- [ ] Untested procedures are declared untested; drills are first-class content.
- [ ] Guarantees are stated at exactly the strength of their evidence.
- [ ] Humor arises from the material itself; no decorative jokes.
