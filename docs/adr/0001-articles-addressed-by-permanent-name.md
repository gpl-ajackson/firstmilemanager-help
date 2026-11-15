# Articles are addressed by a permanent name, with no section in it

> Copied from Stockist's help centre with the app (help#1). It holds here as written, with First Mile Manager in place of Stockist; ADR-0006 says where First Mile Manager's help centre differs.

Every Article lives at `/articles/<Article Name>`, where the name is chosen when it is first published and never changed or reused. Links to Articles are copied into places we can't update: Account Managers' emails to clients, Stockist screens and messages (SR3), the main repo's specs and plans (S7), and each 3PL's own pages and panels in its branded help centre (SR4), where the same path is served from the 3PL's address. So an address, once published, must always lead somewhere. Moving an Article to another Product Area, or rewording its title, changes nothing in its address. If its subject changes so much that the name is wrong, it is a new Article and the old one is retired.

A part of an Article that something links to gets a permanent Section Name (`#<name>`), kept when the heading is reworded; a removed Section Name sends its links to the top of the Article, and the review removing it says where they should now go. A Retired Article's address is kept for good: it redirects to its replacement (a merged Article redirects to the one that stays), or shows a short notice with links to its Product Area and to search. Article Names never contain "stockist", so they read correctly in any 3PL's help centre. Other kinds of page (sections, glossary terms, Release Notes, and in SR4 a 3PL's own pages) have their own prefixes, so they never collide with Articles.

## Considered options

- **Number plus words** (`/articles/1042-receive-a-shipment`): renames are free, but numbers must be handed out centrally while several agents draft on separate branches, and a number means nothing when quoted in a spec.
- **Section plus name** (`/inbound/receive-a-shipment`): shows where an Article lives, but every move between sections needs a redirect kept forever, and it would freeze section names before the site's structure is decided.
- **Links to headings by their wording**: no work for writers, but every reworded heading silently breaks in-app links.

Decided by AJ on 23 October 2026 (support site map #5, ticket #8).
