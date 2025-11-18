# ADR-0002: Naming Doctrine and Aliases

## Metadata
- Author: Aleksi Ursin
- Status: Accepted

## Context
- Inconsistent naming and the use of aliases increase cognitive load, complicate automation, and make it harder to reason about flows under pressure.
- mcxForge already defines high-level naming guidance in `AGENTS.md` (for CLI flags, environment variables, and JSON/config fields), but a focused doctrine is useful to keep tools and docs aligned as the toolkit grows.

## Decision
- Use a single canonical name for each concept across code, CLI flags, logs, and documentation. Do not introduce alternate names or synonyms for the same thing.
  - Example: if we call something `targetDisk` in JSON/config, we do not also call it `disk`, `dst`, or `destinationDrive` elsewhere.
  - For CLI, do not add short or alternate flags for the same option (e.g., `--device` only, not `--dev`).
- Prefer descriptive, long-form identifiers over ambiguous shorthands:
  - `datacenterId`, `chassisId`, `targetDisk` are preferred over `dc`, `ch`, `dst`.
- Respect the naming rails from `AGENTS.md`:
  - CLI flags: long, kebab-case (for example, `--target-disk`, `--profile-name`).
  - Environment variables: `UPPER_SNAKE_CASE`.
  - JSON/config fields: use a consistent style within a document or schema and do not mix styles for the same concept.
  - Shell functions and locals: use a single style per file (lower_snake_case or lowerCamelCase) and stay consistent.
  - PHP follows PSR-12 style: classes in StudlyCaps, methods in lowerCamelCase, and descriptive names for public methods.
- When introducing a new concept, choose the name carefully up front and propagate it everywhere, rather than “patching in” aliases later.

## Consequences
- Easier mental model for operators and automation: the same concept has the same name across scripts, flags, logs, and JSON.
- Simplifies future tooling (linters, schema validators, docs generators) that may rely on naming conventions.
- May require small renames when tightening up existing scripts, but avoids much larger migrations later.

