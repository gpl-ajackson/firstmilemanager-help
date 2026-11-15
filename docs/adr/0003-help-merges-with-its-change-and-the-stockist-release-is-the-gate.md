# Help merges with its change, and the FMM Release is the gate

> Copied from Stockist's help centre with the app (help#1). It holds here as written, with First Mile Manager in place of Stockist; ADR-0006 says where First Mile Manager's help centre differs.

Amended by ADR-0006: the Release Check also retakes First Mile Manager's Pictures of Stockist screens, read from its repository's `main`. A release waits until every one of them is what the version being released shows, so it waits for First Mile Manager's help update to merge.

A main-project change and its Help Proposal are merged together, the change first and its help straight after, so the help centre always matches the main project's `main`. A spec built ticket by ticket has one Help Proposal, linked from every ticket's pull request and merged straight after the last one. "Live on release day" (business rule 1) is enforced at the **FMM Release** instead of at each merge: today that is AJ promoting the `demo` branch, and once 3PLs use Stockist for real, a production deploy. Before each one, an agent's Release Check lists every change since the last release with its help status, and the release waits until each is merged or said "No help changes". Each FMM Release with anything new to a reader gets one Release Note, gathered from the Change Summaries the Help Proposals left in a Next Release folder.

This means help can be a few days ahead of the demo, but never behind it. A reader may meet an Article about a change that `main` has but the demo doesn't yet. We accept that, because the alternative holds help back and lets it collide.

## Considered options

- **Every merge to `main` is a release, with a weekly Release Note.** This is simpler, but features reach `main` a ticket at a time, and the Release Notes would describe work no reader can see yet.
- **Help waits for the release.** Help Proposals would be approved with their change but stay open until the promotion, and then merge all together. Help would never be ahead of the demo, but Help Proposals would be open for days and collide whenever two changes touch one Article.
- **Named versions** (Stockist 0.4 and so on), each with its own help and notes. This adds a release process nobody needs before the first 3PL.
- **Help merges first.** Help would then describe something that isn't on `main` yet, which breaks the rule that nothing is written about unbuilt features (#9).

Decided by AJ on 23 October 2026 (support site map #5, ticket #11).
