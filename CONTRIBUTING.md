# Contributing to Velox Map Locator

Thank you for your interest in improving Velox Map Locator.

Velox Map Locator is maintained by Velox Plugins. GitHub is used for development, issue tracking, testing, and release preparation. WordPress.org SVN is used as the public release distribution repository.

## Before You Start

Please use the repository's issue forms before beginning substantial work:

- Use **Bug report** for reproducible problems.
- Use **Feature request** for proposed improvements or new functionality.
- Use the WordPress.org support forum for installation, configuration, and general usage questions.
- Do **not** open a public issue for a security vulnerability. Follow `SECURITY.md` instead.

For larger changes, opening or discussing an issue first helps avoid duplicated or conflicting work.

## Development Workflow

1. Work from the latest `main` branch.
2. Create a focused branch for the change.
3. Keep each change limited to one clear purpose.
4. Use clear, descriptive commit messages.
5. Open a pull request back to `main`.
6. Explain what changed, why it changed, and how it was tested.

Suggested branch names include:

- `fix/short-description`
- `feature/short-description`
- `docs/short-description`
- `maintenance/short-description`

## Coding Expectations

Contributions should:

- Follow WordPress coding and security best practices.
- Preserve compatibility with the plugin's documented minimum WordPress and PHP versions.
- Sanitize and validate incoming data appropriately.
- Escape output appropriately.
- Use nonces and capability checks for privileged actions.
- Avoid introducing external telemetry, tracking, or unnecessary remote dependencies.
- Preserve accessibility, responsive behavior, and RTL compatibility where relevant.
- Keep public-facing strings translatable.
- Avoid committing credentials, API keys, tokens, passwords, or environment-specific secrets.

## Source and Build Files

The GitHub repository is the complete development project. The WordPress.org distribution contains only the files required for the distributable plugin and its appropriate source materials.

`.distignore` defines development-only files that must not be included in the release package.

Do not commit generated ZIP archives to the repository.

## Testing

Before submitting a pull request, test the affected functionality in a WordPress installation.

Where applicable, also check:

- PHP syntax
- WordPress Plugin Check
- affected admin screens
- affected frontend output
- responsive behavior
- Light/Dark or RTL behavior when relevant
- activation, deactivation, upgrade, and uninstall behavior when affected by the change

Additional automated checks may be added to the repository over time.

## Documentation and Changelog

For user-facing changes, update the relevant documentation when necessary.

Do not change the plugin version number, WordPress.org stable tag, or create a release tag unless the change is specifically part of a coordinated Velox Plugins release.

## Pull Requests

A good pull request should:

- have a focused title;
- link to the related issue when one exists;
- explain the problem and solution;
- describe testing performed;
- avoid unrelated formatting or refactoring changes;
- contain no secrets or private information.

By submitting a contribution, you agree that your contribution may be distributed under the same license as Velox Map Locator.

## License

Velox Map Locator is licensed under the GNU General Public License v2.0 or later.
