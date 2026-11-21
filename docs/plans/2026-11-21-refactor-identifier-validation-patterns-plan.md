---
title: Identifier Validation Patterns - Plan
type: refactor
date: 2026-11-21
---

# Identifier Validation Patterns - Plan

## Summary

Make `PublicId` the source of its identifier syntax in PHP and OpenAPI. Reuse the existing `MusicbrainzId` pattern where the Catalog domain checks the same format.

## Problem Frame

Application PHP repeats the 21-character Public ID pattern across request validation, route requirements, lookups, and OpenAPI schemas. The constructor checks byte length before applying a regex with `$`, which accepts a position before a final newline. It therefore accepts a 20-character ID followed by a newline even though strict request validators reject it.

## Product Contract

- R1. Public ID validators and declarations use the pattern owned by `PublicId`.
- R2. A valid Public ID contains exactly 21 ASCII letters, digits, underscores, or hyphens. Keep existing valid IDs, route matches, schema bounds, and constructor error messages. Reject trailing newlines in PHP validation.
- R3. Use the `MusicbrainzId` pattern for Genre MusicBrainz IDs while preserving the existing treatment of case and whitespace.

## Key Technical Decisions

- KTD1. Publish `LENGTH`, a pattern fragment, an anchored OpenAPI pattern, and a strictly anchored PHP regex on `PublicId`. Derive the patterns from one fragment. Symfony route constraints and embedded captures consume the fragment. OpenAPI needs a portable pattern without PHP delimiters or flags. Governs R1–R2.
- KTD2. Keep other encodings and identifier policies independent. `Uuid` uses Symfony UID validation. Metadata's MusicBrainz URL parser stays within its context. Standalone qualification packages keep their dependency boundary. Published migrations and deliberately different test fixtures retain their literals. Governs R1 and R3.

## Implementation Units

### U1. Centralize Public ID patterns

- Covers R1–R2 and KTD1–KTD2.
- Update `src/Shared/Domain/Model/PublicId.php` and the application PHP consumers. Use `LENGTH` for matching OpenAPI bounds and validation lengths. Regenerate `openapi.json` and check that pattern spelling is the only exported change.
- Extend `PublicIdTest` for the trailing-newline case and run identifier, route, request, and OpenAPI checks in the CI image.

### U2. Reuse the MusicBrainz pattern in Genre

- Covers R3 and KTD2.
- Expose `MusicbrainzId`'s existing regex, use it in `Genre`, and verify existing Catalog behavior.

## Verification

- Run PHPStan, Deptrac, container lint, and the OpenAPI export check. Record test counts and static results in `ROADMAP.md`.
