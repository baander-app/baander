# Register: Raymond Chen (The Old New Thing)

Chen explains how a system actually behaves and why it ended up that way. His essays
are case reports from real machines: a customer hits something weird, the mechanism is
walked through step by step, the history that produced the weirdness is told plainly,
and the lesson is left to land on its own. The humor is a raised eyebrow, never a
punchline. Sources read for this sheet: "The case of the string being copied from a
mysterious pointer to invalid memory" (2024), "Keep your eye on the code page" (2005),
"Inside STL: The deque, design" and "…implementation" (2023), "Why does MS-DOS use 8.3
filenames instead of, say, 11.2 or 16.16?" (2009), "The case of the fail-fast trying to
log a caught exception" (2024).

**Assign Chen to:** user guides, manuals, setup and troubleshooting documents,
tutorials, authoring guides, reviews and postmortems — anything whose reader is trying
to *do* something or understand *why something happened*.

## The principles, with the source in evidence

### 1. Open with the scenario, not the definition

The first sentence is a situation somebody was in, or a question somebody asked.
Definitions arrive when the story needs them.

> "A customer ran some stress tests on their program with Application Verifier enabled.
> Thanks for doing that!"

> "When I discussed years ago why operating system files tend to follow the old 8.3
> file name convention, I neglected to mention why the old MS-DOS filename convention
> was 8.3 and not, say, 11.2 or 16.16."

Note the specificity: not "many users experience problems" but *a* customer, *three*
machines, *rare but repeated* crashes. Vague crowds are an LLM tic; a single concrete
case is the Chen opening.

### 2. Walk the mechanism in the order it happens

Explain behavior as a sequence the machine performs, each step causing the next.
From "Keep your eye on the code page":

> "If you type "dir" at a command prompt, you see a happy Ç on the screen. On the other
> hand, if you do "dir >files.txt" and open files.txt in a GUI editor like Notepad, you
> will find that the Ç has changed to a €, because the 0x80 in the file is being
> interpreted in the ANSI character set instead of the OEM character set."

One phenomenon, one concrete command, one observed result, one cause — then the next
step builds on it ("Stranger yet, …", "But wait, there's more."). Never front-load a
taxonomy; earn each fact with the previous one.

### 3. Build a simple model first, then admit where reality gets messy

From "Inside STL: The deque, design": he designs a toy `simple_deque` with four obvious
operations, shows exactly when it breaks ("Now, this is an inefficient data structure
because both of the alternatives for freeing up spares are O(n)"), and only then shows
the real structure. The design article ends:

> "Next time, we'll dig into the implementations. That's where things get messy."

Teach the model the reader could have invented, then correct it. The correction sticks
because the reader felt why it was needed.

### 4. Say "I don't know" when you don't know

> "Why did CP/M use 8.3 filenames? I don't know. There's nothing obvious in the CP/M
> directory format that explains why those two reserved bytes couldn't have been used
> to extend the file name to 10.3. But maybe they figured that eight was a convenient
> number."

> "(Why Swedish yet not Danish and Norwegian I don't know.)"

An unanswered sub-question is stated, its plausible answer offered *as* speculation,
and the text moves on. Confidence is calibrated sentence by sentence. Nothing in a
Chen piece claims more than the evidence shown.

### 5. Mark speculation explicitly; verify in public

> "My guess is that we had a race condition where two threads called GetId() at the
> same time…"

> "Note: This is just me reading the headers. You should check the documentation to
> confirm that providing a custom exception thrower is supported. I tried to read the
> Azure Cognitive Services SDK documentation and I couldn't find any discussion of
> exceptions at all! Maybe you will have better luck."

Guesses are labeled "My guess", deductions are labeled by the evidence that produced
them ("The timestamps and GlobalIndex let us reconstruct the chronology"), and the
reader is told when the author failed to verify something. This is what makes the rest
of the text trustworthy.

### 6. The humor is dry, structural, and self-deprecating

> "I mean, look at its name: It's "Get". It just reads something!"

> "(here's where things go bad)"

> "The raw view reveals the soft underbelly of the implementation."

> "A customer was running Windows Server 2003 ("Still in support until 2015!")"

The joke is always inside a parenthesis or a short sentence, always at the author's own
expense or the situation's, never at the reader's. One per section is plenty.

### 7. History is an explanation, not a digression

> "The reason is, of course, historical."

Present-day weirdness is explained by telling the decision that made sense at the
time: box-drawing characters evicted for Canadian French; 8.3 inherited from CP/M.
The telling is neutral — no mocking of the old decision, because the essay's point is
that every system's oddities were once reasonable.

### 8. Tangents go to "Bonus chatter" or a footnote

The main line stays clean; the side observation is explicitly cordoned:

> "Bonus chatter: As I noted at the start of the series, the primary purpose of these
> articles is to explain how to extract the contents of these collection classes when
> you encounter them in a crash dump."

Scope honesty again: the reader is told what the article is *for*, so they can judge
whether they're reading the right one.

### 9. End when the mechanism is explained

The detective article ends with the fix — two of them, offered neutrally ("One way to
fix this is… Another way to fix this is…") — and stops. No summary, no "In conclusion",
no outlook. When the reader has what they came for, the essay is over.

### 10. Understatement is the strongest emphasis

> "One such hit might be chalked up to a flaky CPU, but they had three, from three
> different machines."

The damning fact is presented without an adjective. Chen never writes "shockingly",
"alarmingly", "it is critical that". The facts carry the alarm.

## Before / after (Baander documentation → Chen register)

These examples illustrate prose technique using the repository's documentation;
verify the target document's facts rather than treating examples as a current spec.

**Before:** "It is important to note that all of the console commands are run inside
the app container, and you can use `make exec cmd="php bin/console <command>"`
to run one."
**After:** "Console commands run inside the app container. Use
`make exec cmd="php bin/console <command>"` to run one."

**Before:** "Each bounded context is organized by responsibility into layers. It is
important to note that Domain holds business rules, Application holds use cases,
Infrastructure provides adapters, and Interface handles incoming requests."
**After:** "Each bounded context groups its code by responsibility: Domain holds
business rules, Application holds use cases, Infrastructure provides adapters, and
Interface handles incoming requests."

## Chen checklist

- [ ] First sentence is a situation or a question, not a definition or a boast.
- [ ] Every mechanism is walked in execution order; no unexplained leaps.
- [ ] Simplified model taught before the real one, if the real one is messy.
- [ ] Every uncertain claim labeled: guess, deduction, or known fact.
- [ ] At least one plain "I don't know" wherever the record genuinely runs out.
- [ ] Humor present but rare, parenthetical, never at the reader's expense.
- [ ] Historical causes told neutrally, without mocking old decisions.
- [ ] No summary paragraph; the text stops when the explanation is complete.
- [ ] Zero adjectives of enthusiasm; the facts do the impressing.
- [ ] Tangents cordoned as bonus chatter, footnotes, or appendix.
