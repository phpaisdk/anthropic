# Changelog

Notable changes to `aisdk/anthropic`. Release notes are also published at https://github.com/phpaisdk/anthropic/releases.

## v0.11.0 — 2026-10-31

- Requires `aisdk/core ^0.11.0`. Upgrade SDK packages together; no other changes in this package.

## v0.10.2 — 2026-10-29

- Report cumulative streamed input, output and cache usage accurately, plus reasoning tokens when Anthropic supplies them.
- Automatically continue paused server-tool turns, including streams, up to five times; combine generated content and token usage.
- Distinguish refusals and exhausted context windows in synchronous and streamed finish reasons while preserving the raw reason.

## v0.10.1 — 2026-10-29

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
