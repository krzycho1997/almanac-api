## What and why

<!-- 2–3 sentences: what this slice changes and why. -->

## How to verify

<!-- Commands a reviewer can run, and the evidence you already have (CI, clean checkout, Podman). -->

## Decisions and trade-offs

<!-- Anything a reviewer might question. If this PR introduces or changes a
decision, add 2–5 sentences about it to the README as well (Decisions, or
the matching block under Details). -->

## Out of scope

<!-- What this PR deliberately leaves for later, and in which slice. -->

## Checklist

- [ ] `composer check` is green in the container
- [ ] Tests cover the new path and its failure path
- [ ] No secrets or personal data in logs, fixtures or `.env`
- [ ] `.env.example` is updated (same keys as `.env`)
- [ ] The README is updated if this PR introduces a decision
