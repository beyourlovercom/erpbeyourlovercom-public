# Public source scope

This is a sanitized code snapshot without its former private Git history. The published tree includes the runtime application, configuration examples, test fixtures, and interface contracts; it intentionally excludes private project operations and internal AI instructions (for example docs/, AI/, .opencode/, .codex/, AGENTS.md and associated entrypoints), as well as credentials and local database data.

Tests and credentials must use synthetic fixtures. End-to-end runtime integration and production deployments require separate validation. This repository is intended as the single public source-code target **after** authorized repository cutover; before that event the existing private source remains operationally authoritative.
