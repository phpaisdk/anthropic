# Changelog

Notable changes to `aisdk/anthropic`. Release notes are also published at https://github.com/phpaisdk/anthropic/releases.

## Unreleased (v0.10.1)

- Responses without content and streams that never finish raise `InvalidResponseException`; stream errors are typed (for example `OverloadedException`).
- Raise SDK exceptions instead of native PHP ones.

## v0.10.0 — 2026-10-29

### What's new

- Manage files with `Anthropic::files()` and attach stored documents and images.
- Prompt-cache hints map to `cache_control`; usage reports cache reads and writes.
- Response id and raw stop reason are available for synchronous and streamed generation.
- Required token defaults apply only when you omit them.

Provider-generated files can be downloaded; uploaded inputs cannot (Anthropic's restriction).

### Compatibility

- Requires `aisdk/core ^0.10.0`. Upgrade SDK packages together.

## Earlier releases

See the [GitHub releases](https://github.com/phpaisdk/anthropic/releases) for versions before 0.10.0.
