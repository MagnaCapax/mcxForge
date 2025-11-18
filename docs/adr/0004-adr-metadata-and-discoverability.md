# ADR-0004: ADR Metadata and Discoverability

## Metadata
- Author: Aleksi Ursin
- Status: Accepted

## Context
- As mcxForge grows, the number of ADRs will increase, and filename-based discovery alone becomes less effective.
- We already treat ADRs as part of the mcxForge “constitution” (see `AGENTS.md`) and enforce that each ADR has an `Author:` line via `development/testing/adr-lint.sh`.
- A small amount of structured metadata and consistent titling makes ADRs easier to find and reference without adding heavy documentation machinery.

## Decision
- Title format:
  - All ADRs MUST use an H1 of the form: `# ADR-XXXX: <Descriptive Title>`, where `XXXX` is a zero-padded sequence number.
- Metadata:
  - All ADRs MUST include a `## Metadata` section with at least:
    - `Author: <Name>`
    - `Status: <Proposed|Accepted|Superseded|Rejected>`
  - Authors MAY also include `Date:` and `Category:` lines when useful (for example, `Category: architecture`).
- Discoverability:
  - ADRs remain discoverable primarily via their filenames, titles, and in-repo search (`rg`, `grep`).
  - `docs/adr/0000-template.md` is the canonical template for new ADRs and MUST be used when drafting new decisions.
- Tooling:
  - `development/testing/adr-lint.sh` MUST check for the presence of an `Author:` line in each ADR.
  - Future enhancements to `adr-lint.sh` MAY add checks for title format, `Status:`, and optional categories, as long as they remain simple and fast.

## Consequences
- ADR authors have a clear, lightweight template to follow, and consumers can quickly scan titles and metadata to find relevant decisions.
- Linting keeps ADR metadata from drifting or being forgotten as the repository evolves.
- The decision avoids maintaining central ADR indexes or README link lists, keeping documentation DRY and focused inside `docs/adr/`.

