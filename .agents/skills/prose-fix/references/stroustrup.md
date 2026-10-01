# Register: Bjarne Stroustrup (The C++ Programming Language, 4th ed.)

Stroustrup writes declarative prose about designed systems: each paragraph opens with
a sentence that names a fact or a mechanism, terms are defined at first use, cross-
references do the work of repetition, and judgment is delivered as numbered advice
that trusts the reader's intelligence. The tone is calm, exact, and quietly witty.
Sources read for this sheet: Chapter 1 "Notes to the Reader" (structure, design,
learning, history, advice), and Chapter 2 "A Tour of C++: The Basics" (§2.1–§2.2).

**Assign Stroustrup to:** design documents, specifications, format references, policy
documents — anything whose job is to state what a system *is* and *why the rules are
the rules*.

## The principles, with the source in evidence

### 1. Open paragraphs with a declarative sentence that names the mechanism

> "C++ is a compiled language. For a program to run, its source text has to be
> processed by a compiler, producing object files, which are combined by a linker
> yielding an executable program."

> "C++ is a statically typed language. That is, the type of every entity (e.g.,
> object, value, name, and expression) must be known to the compiler at its point of
> use."

The topic sentence is a fact; the second sentence unpacks it ("That is, …"). A design
document in this register is a chain of such paragraphs: claim, then mechanism, then
consequence. No throat-clearing, no question-hooks.

### 2. Define every term at first use, and say when usage is loose

> "An executable program is created for a specific hardware/system combination; it is
> not portable, say, from a Mac to a Windows PC. When we talk about portability of
> C++ programs, we usually mean portability of source code; that is, the source code
> can be successfully compiled and run on a variety of systems."

> "A C++ program typically consists of many source code files (usually simply called
> source files)."

The definition arrives the moment the word does, includes the boundary case ("not
portable, say, from a Mac to a Windows PC"), and names the convention the rest of the document
will follow ("usually simply called", "we usually mean"). A spec written this way can
be read from any section, because no term depends on unstated shared assumptions.

### 3. Cross-references carry the repetition load

> "The std:: specifies that the name cout is to be found in the standard-library
> namespace (§2.4.2, Chapter 14)."

> "This defines a function called main, which takes no arguments and does nothing
> (§15.4)."

Instead of re-explaining, point. Instead of omitting, point. The § reference is the
unit of trust: the reader can always go deeper, so the main line stays at one level of
abstraction per paragraph. Stroustrup's documents are "heavily cross-referenced both
to [themselves] and to the ISO C++ standard" — cross-reference density is a feature to
preserve, never to thin out during a rewrite.

### 4. Hedge honestly, with the specific cases named

> "A nonzero value from main() indicates failure. Not every operating system and
> execution environment make use of that return value: Linux/Unix-based environments
> often do, but Windows-based environments rarely do."

The general rule is stated, then the exceptions are enumerated by name — not "some
systems may vary" but *which* systems and *how* they vary. Hedging without the
enumeration is vagueness; the enumeration is what makes the hedge useful.

### 5. Use one good analogy, then cash it in

> "As an analogy, think of a short sightseeing tour of a city, such as Copenhagen or
> New York. In just a few hours, you are given a quick peek at the major attractions,
> told a few background stories, and usually given some suggestions about what to see
> next. You do not know the city after such a tour. … After the tour, the real
> exploration can begin."

One analogy, extended for a paragraph, then mapped back onto the technical claim
("This tour presents C++ as an integrated whole, rather than as a layer cake"). Never
stack metaphors; one per concept, chosen for structural accuracy rather than
colorfulness.

### 6. Deliver judgment as numbered rules of thumb, with references

> "[3] Don't overabstract; §1.2."
> "[11] Express simple ideas simply; §1.2.1."
> "[14] Low-level code is not necessarily efficient; don't avoid classes, templates,
> and standard-library components out of fear of performance problems; §1.2.4, §1.3.3."

And the philosophy behind the phrasing:

> "Such advice consists of rough rules of thumb, not immutable laws. A piece of advice
> should be applied only where reasonable. There is no substitute for intelligence,
> experience, common sense, and good taste."

> "I find rules of the form "never do this" unhelpful. Consequently, most advice is
> phrased as suggestions for what to do. Negative suggestions tend not to be phrased
> as absolute prohibitions and I try to suggest alternatives. I know of no major
> feature of C++ that I have not seen put to good use."

In a policy document this becomes: normative rules carry their rationale and their
escape hatches; prohibitions name the alternative; and the document trusts the reader
to apply judgment. Preserve existing hard requirements as requirements; this
register does not authorize weakening a prohibition or inventing an exception.

### 7. Be honest about the document's own structure

> "A pure tutorial sorts its topics so that no concept is used before it has been
> introduced; it must be read linearly starting with page one. Conversely, a pure
> reference manual can be accessed starting at any point… This book combines aspects
> of both."

> "Making parts of the book relatively self-contained implies some repetition, but
> repetition also serves as review for people reading the book linearly."

> "The assumption is that you have programmed before. If not, please consider reading
> a textbook … before continuing here."

Say what the document is, whom it assumes, how to read it, and what its compromises
cost. A spec that explains itself this way needs no separate "how to read this"
meta-document.

### 8. Wit is one clause, delivered straight

> "In particular, it should convince readers that C++ has come a long way since the
> first, second, and third editions of this book."

> "The exception to this rule is exceptions."

> "In general: To write a good program takes intelligence, taste, and patience. You
> are not going to get it right the first time. Experiment!"

The joke — when it comes — is a single clause that also happens to be true, and the
text moves on without waiting for a laugh.

## Before / after (Baander documentation → Stroustrup register)

These examples illustrate the register; they do not replace the repository's
architecture and convention sources.

**Before:** "The Shared kernel is a very important part of the architecture because
it provides cross-cutting infrastructure for all the different contexts, although
it is not itself a bounded context."
**After:** "The Shared kernel provides cross-cutting infrastructure for all contexts.
It is not a bounded context."

**Before:** "It is important to understand that each bounded context represents a
distinct business capability and has its own domain model, rules, and language."
**After:** "Each bounded context represents a distinct business capability with its
own domain model, rules, and language."

## Stroustrup checklist

- [ ] Every paragraph opens with a declarative sentence naming a fact or mechanism.
- [ ] Every term is defined at first use, including loose-usage conventions.
- [ ] Cross-references (§) carry repetition; density is preserved, not thinned.
- [ ] Every hedge enumerates its specific exceptions.
- [ ] At most one analogy per concept, mapped back onto the technical claim.
- [ ] Normative rules carry rationale; prohibitions name the permitted alternative.
- [ ] The document states its own structure, audience assumptions, and compromises.
- [ ] Wit is a single true clause; the text does not wait for applause.
- [ ] No summary that merely repeats; the last mechanism is the ending.
