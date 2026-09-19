# Velox Map Locator — Core Development Workspace

This branch is a development workspace for **Velox Map Locator Core 1.0.0**.

It preserves the existing Core runtime source and adds the missing reproducible development toolchain requested before Pro development begins.

## Source of truth

- Repository: `velox-plugins/velox-map-locator`
- Core baseline: `main` at commit `df0fb6e65b1955cf202f606f4f1d0d6a425bd97d`
- Published Core version: `1.0.0`
- Human-readable browser source: `src/`
- Production browser assets: `build/`

The WordPress.org SVN repository remains a distribution target, not the primary development repository.

## Requirements

- Node.js 20 or later
- npm 10 or later
- PHP 7.4 or later
- Composer 2
- A WordPress 6.6+ test installation for integration/manual QA

## JavaScript/CSS setup

```bash
npm install
npm run build
```

Development watch mode:

```bash
npm run start
```

The custom Webpack configuration uses `@wordpress/scripts` and builds these entries:

- `src/admin/index.js` → `build/admin.js` + `build/admin.css`
- `src/frontend/index.js` → `build/frontend.js` + `build/frontend.css`
- `src/frontend/map-google.js` → `build/map-google.js`
- `src/frontend/map-leaflet.js` → `build/map-leaflet.js`
- `src/block/index.js` → `build/block/index.js`

It also copies block metadata/editor CSS and writes the dependency asset files required by WordPress.

## PHP setup

```bash
composer install
composer test
composer lint
```

The initial PHPUnit suite deliberately targets domain code that can be tested without a full WordPress bootstrap. Integration tests can be added later for REST, CPT, repository and rendering behavior.

## JavaScript tests

```bash
npm run test:js
```

## Build and release ZIP

```bash
npm run plugin-zip
```

`.distignore` excludes development-only tooling from the distributable plugin package.

## Important Core/Pro rule

The Pro Edition should be a separate add-on plugin. Do not fork or duplicate Core business logic into Pro.

Before Pro relies on a Core integration point, first verify that the corresponding Core action/filter is actually present and covered by a regression test. If a required hook is missing, add the smallest stable hook to Core in a separate reviewed change.

## What this workspace does not do

It does not change the public Core feature set, data model, plugin version, or WordPress.org release. The additional files are development infrastructure only.
