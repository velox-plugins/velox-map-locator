# Workspace Manifest

**Workspace:** Velox Map Locator Core Development Workspace  
**Core version:** 1.0.0  
**Baseline branch:** main  
**Baseline commit:** df0fb6e65b1955cf202f606f4f1d0d6a425bd97d  
**Workspace branch:** core-dev-workspace-1.0.0

## Added development infrastructure

- `package.json`
- `webpack.config.js`
- `composer.json`
- `phpunit.xml.dist`
- `phpcs.xml.dist`
- `.nvmrc`
- `DEVELOPMENT.md`
- `tests/`
- `.github/workflows/ci.yml`

## Runtime code policy

No existing PHP, JavaScript, CSS, block metadata, map engine, Leaflet vendor asset, or built production asset was intentionally changed while creating this workspace.

The purpose of this branch is to make the existing Core source reproducibly buildable and testable before Velox Map Locator Pro is developed against it.
