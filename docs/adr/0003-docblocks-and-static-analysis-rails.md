# ADR-0003: Docblocks and Static Analysis Rails

## Metadata
- Author: Aleksi Ursin
- Status: Accepted

## Context
- mcxForge relies on PHP for more complex flows and shared libraries under `lib/php/`. Contracts for these libraries must live in code so they remain close to behavior and are enforced by tooling.
- Clear docblocks and static analysis make it easier to evolve tools safely and to keep behavior predictable on live rescue systems.

## Decision
- For shared PHP code under `lib/php/` and other reusable PHP libraries:
  - Every class and public method MUST have a docblock.
  - Public method docblocks SHOULD include:
    - A short description that explains why the method exists / what it does (not just tags).
    - `@param` tags for parameters where the intent or types are not obvious from the signature alone.
    - `@return` tags where the return type is not self-evident or when returning structured arrays.
- For CLI entrypoints under `bin/`:
  - Top-of-file docblocks SHOULD describe the purpose, safety characteristics (read-only vs destructive), and primary usage of the tool.
  - Detailed behavior and examples may live in `docs/` and are not required to be exhaustively repeated in code.
- Static analysis:
  - `development/testing/php-lint.sh` must remain clean (`php -l` over all PHP files).
  - `development/testing/phpstan.sh` must remain clean at the configured level; new PHP code is expected to pass PHPStan without introducing new errors.
  - `development/testing/test.sh` is the single entrypoint for running PHP lint and PHPStan locally and in CI.
- Future lints:
  - Additional lightweight docblock or naming linters MAY be introduced later if needed, but must respect KISS/YAGNI and be wired through `development/testing/test.sh`.

## Consequences
- PHP libraries become self-documenting: readers can understand contracts and expectations from docblocks and types.
- Static analysis remains a reliable early warning system for refactors and new tools.
- Slightly more upfront effort when creating shared PHP code, with payoff in easier maintenance and safer evolution.

