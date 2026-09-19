# Tests

This workspace starts with two deliberately small automated test layers.

- `tests/php` contains PHPUnit tests for Core domain objects that can run without booting WordPress.
- `tests/js` contains Jest workspace/build-contract tests through `@wordpress/scripts`.

These tests are a foundation, not a claim of complete coverage. Pro development should add regression tests around any Core hooks or contracts it depends on before those contracts are changed.
