# Pictures are scripted from a fixed-date Trentside copy and checked on every change

> Copied from Stockist's help centre with the app (help#1). It holds here as written, with First Mile Manager in place of Stockist; ADR-0006 says where First Mile Manager's help centre differs.

Amended by ADR-0006: First Mile Manager's help keeps its Pictures of Stockist screens and their Recipes in its own repository, beside the help that shows them. They are taken with this tool, and the Release Check retakes them, but a pull request's Picture Check and the Help Drift Report don't.

Every picture of a Stockist screen in an Article is a Picture taken by a picture tool in this repository, never by hand. The tool seeds a fresh Trentside copy from the version of Stockist asked for, with the clock set to one fixed Picture Date (a Wednesday at 10:30 UK time, moved once a year in January), and follows each Picture's written Picture Recipe through Stockist's own screens in a headless browser, signed in as the person the Article is written for. Because the code, the seed and the moment are fixed, the same screen always gives the same Picture, so a changed Picture means a changed screen. That lets a Picture Check retake every Picture and list the differences on every main-project pull request, every support-site pull request, the Release Check and the monthly Help Drift Report. A main-project change that changes a Picture can't say `No help changes`; its builder's agent retakes the Pictures in its Help Proposal, Demo Walks the Articles that use them, and shows each one before and after. Client-tier Pictures crop out the app's top bar, are taken with the 3PL's display name set to "Your 3PL", and fail if "Stockist" or "Trentside" shows, so rule 14 is checked by the tool, not by eye.

The Trentside seeder dates its stories back from the moment it runs, so without a fixed moment every retake would show different dates and ages, and a changed picture would mean nothing. Restyles marked `No help changes` and changes to the Trentside stories also alter screens without anyone writing help, and only an automatic check on every change catches them while the person who made the change still knows why. The tool and its Recipes live here, with the help they serve; Stockist only has to stay seedable at a fixed moment (ADR-0004, rule 21).

## Considered options

- **Pictures from the public demo** (`demo.firstmilemanager.co.uk`): already running, but each visitor copy carries a demo banner and copy-specific sign-in addresses, its dates move every night, and it is only as new as the last `demo` promotion, while help merges straight after its change on `main` (ADR-0003).
- **Taken by hand during the Demo Walk**: nothing to build, but every refresh is the same manual work again, two agents' pictures differ in size, crop and state, and nothing notices a screen that changed under a `No help changes` pull request.
- **Retaken on a timer** (nightly or monthly), or **checked only at the Release Check**: cheaper in CI, but a stale picture is found days or weeks after the change, by someone who doesn't know why the screen moved.
- **Browser tests in the main repository**: closer to the code, but Pictures and their Recipes would be split from the Articles that use them, and every Recipe change would need a main-project pull request.
- **Client pictures that simply refuse any screen naming the 3PL**: no renaming, but useful client screens such as a held account's notice could never have a picture.

Decided by AJ on 24 October 2026 (support site map #5, ticket #21).
