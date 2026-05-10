# Graph Report - Pterodactyl - modules  (2026-05-10)

## Corpus Check
- 7 files · ~35,456 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 27 nodes · 25 edges · 2 communities detected
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Community 0|Community 0]]
- [[_COMMUNITY_Community 1|Community 1]]

## God Nodes (most connected - your core abstractions)
1. `csrfHeaders()` - 3 edges
2. `ensureSanctumCsrfCookie()` - 3 edges
3. `postJson()` - 3 edges
4. `jsonDelete()` - 3 edges
5. `pinLookupKey()` - 2 edges
6. `dedupeHistoryNewestFirst()` - 2 edges

## Surprising Connections (you probably didn't know these)
- None detected - all connections are within the same source files.

## Communities

### Community 0 - "Community 0"
Cohesion: 0.25
Nodes (2): dedupeHistoryNewestFirst(), pinLookupKey()

### Community 1 - "Community 1"
Cohesion: 0.67
Nodes (4): csrfHeaders(), ensureSanctumCsrfCookie(), jsonDelete(), postJson()

## Knowledge Gaps
- **Thin community `Community 0`** (9 nodes): `McPluginsDashboard.tsx`, `dedupeHistoryNewestFirst()`, `fetchJson()`, `fmtBytes()`, `mcCompatForVersion()`, `mcVersionCompatible()`, `onSubmit()`, `pinLookupKey()`, `serverFromPath()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `csrfHeaders()` connect `Community 1` to `Community 0`?**
  _High betweenness centrality (0.001) - this node is a cross-community bridge._
- **Why does `ensureSanctumCsrfCookie()` connect `Community 1` to `Community 0`?**
  _High betweenness centrality (0.001) - this node is a cross-community bridge._
- **Why does `postJson()` connect `Community 1` to `Community 0`?**
  _High betweenness centrality (0.001) - this node is a cross-community bridge._