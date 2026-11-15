# The help centre lives at help.firstmilemanager.co.uk

> Copied from Stockist's help centre with the app (help#1). It holds here as written, with First Mile Manager in place of Stockist; ADR-0006 says where First Mile Manager's help centre differs.

The whole support site lives at its own address, `help.firstmilemanager.co.uk`: articles at `/articles/<Article Name>` (ADR-0001) now, and later the request desk and "Your subscription" (SR2) under their own paths on the same address. Links to it are copied into places we can't update (Account Managers' emails, Stockist screens and messages, main-repo specs), so the address has to hold for good, like the article paths under it. Each 3PL's branded help centre (SR4) is served from its own neutral address that never names Stockist, with the same `/articles/<name>` paths, so nothing here changes then.

## Considered options

- **`support.firstmilemanager.co.uk`**: fits the request desk a little better, but "help" is what people type and what the marketing site's footer already calls it ("Help centre").
- **A path on the marketing site** (`firstmilemanager.co.uk/help`): one domain, but two separately hosted sites (the static Astro marketing site and the Laravel support site) would have to share one address, every request would need routing between them, and SR2's sign-in would sit on the marketing site's domain. SQ6 keeps the two apart.

Decided by AJ on 23 October 2026 (support site map #5, ticket #9).
