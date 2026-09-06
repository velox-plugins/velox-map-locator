from pathlib import Path

ROOT = Path.cwd()


def replace(path, old, new, count=1):
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    if text.count(old) < count:
        raise RuntimeError(f"Patch target missing in {path}: {old[:80]!r}")
    file_path.write_text(text.replace(old, new, count), encoding="utf-8")


# Store an optional global card-padding default. Null means keep the existing theme spacing.
replace(
    "includes/content/class-settings.php",
    "\t\t\t'appearance'       => array(\n\t\t\t\t'radius'  => 10,\n\t\t\t\t'density' => 'comfortable',\n\t\t\t\t'shadow'  => 'soft',\n\t\t\t\t'accent'  => '#2563eb',\n\t\t\t),",
    "\t\t\t'appearance'       => array(\n\t\t\t\t'radius'       => 10,\n\t\t\t\t'density'      => 'comfortable',\n\t\t\t\t'shadow'       => 'soft',\n\t\t\t\t'accent'       => '#2563eb',\n\t\t\t\t'card_padding' => null,\n\t\t\t),",
)
replace(
    "includes/content/class-settings.php",
    "\t\t$output['appearance']['accent']  = isset( $appearance['accent'] ) ? sanitize_hex_color( $appearance['accent'] ) : $defaults['appearance']['accent'];\n\n\t\tif ( empty( $output['appearance']['accent'] ) ) {",
    "\t\t$output['appearance']['accent']  = isset( $appearance['accent'] ) ? sanitize_hex_color( $appearance['accent'] ) : $defaults['appearance']['accent'];\n\t\tif ( array_key_exists( 'card_padding', $appearance ) && null !== $appearance['card_padding'] && '' !== $appearance['card_padding'] ) {\n\t\t\t$output['appearance']['card_padding'] = max( 0, min( 48, absint( $appearance['card_padding'] ) ) );\n\t\t} else {\n\t\t\t$output['appearance']['card_padding'] = $defaults['appearance']['card_padding'];\n\t\t}\n\n\t\tif ( empty( $output['appearance']['accent'] ) ) {",
)

# Allow each Locator to override card padding independently.
replace(
    "includes/services/class-locator-validator.php",
    "\t\t\t'appearance'     => array(\n\t\t\t\t'theme'      => (string) $general['default_theme'],\n\t\t\t\t'mode'       => (string) $general['default_colour_mode'],\n\t\t\t\t'typography' => (string) $general['default_typography'],\n\t\t\t\t'density'    => (string) $appearance_defaults['density'],\n\t\t\t\t'accent'     => (string) $appearance_defaults['accent'],\n\t\t\t),",
    "\t\t\t'appearance'     => array(\n\t\t\t\t'theme'        => (string) $general['default_theme'],\n\t\t\t\t'mode'         => (string) $general['default_colour_mode'],\n\t\t\t\t'typography'   => (string) $general['default_typography'],\n\t\t\t\t'density'      => (string) $appearance_defaults['density'],\n\t\t\t\t'accent'       => (string) $appearance_defaults['accent'],\n\t\t\t\t'card_padding' => array_key_exists( 'card_padding', $appearance_defaults ) && null !== $appearance_defaults['card_padding'] ? (int) $appearance_defaults['card_padding'] : null,\n\t\t\t),",
)
replace(
    "includes/services/class-locator-validator.php",
    "\t\t\tif ( array_key_exists( 'accent', $appearance ) ) {\n\t\t\t\t$accent = is_scalar( $appearance['accent'] ) ? sanitize_hex_color( (string) $appearance['accent'] ) : false;\n\t\t\t\t$config['appearance']['accent'] = $accent ? $accent : '';\n\t\t\t}\n",
    "\t\t\tif ( array_key_exists( 'accent', $appearance ) ) {\n\t\t\t\t$accent = is_scalar( $appearance['accent'] ) ? sanitize_hex_color( (string) $appearance['accent'] ) : false;\n\t\t\t\t$config['appearance']['accent'] = $accent ? $accent : '';\n\t\t\t}\n\t\t\tif ( array_key_exists( 'card_padding', $appearance ) ) {\n\t\t\t\tif ( null === $appearance['card_padding'] || '' === $appearance['card_padding'] ) {\n\t\t\t\t\t$config['appearance']['card_padding'] = null;\n\t\t\t\t} else {\n\t\t\t\t\t$config['appearance']['card_padding'] = max( 0, min( 48, absint( $appearance['card_padding'] ) ) );\n\t\t\t\t}\n\t\t\t}\n",
)

# Add Card Padding to both Locator Appearance and Appearance Defaults.
for path in ("src/admin/index.js", "build/admin.js"):
    replace(
        path,
        "\t\t\t\t\t\th( ColorField, { label: __( 'Accent Colour', 'velox-map-locator' ), value: appearance.accent || '#2563eb', onChange: ( value ) => updateConfig( 'appearance.accent', value ) } )\n",
        "\t\t\t\t\t\th( 'div', { className: 'vml-field-grid two' },\n\t\t\t\t\t\t\th( ColorField, { label: __( 'Accent Colour', 'velox-map-locator' ), value: appearance.accent || '#2563eb', onChange: ( value ) => updateConfig( 'appearance.accent', value ) } ),\n\t\t\t\t\t\t\th( Field, { label: __( 'Card Padding', 'velox-map-locator' ), hint: __( '0–48 px. Leave blank to use the current theme default spacing.', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 0, max: 48, value: appearance.card_padding ?? '', placeholder: __( 'Theme default', 'velox-map-locator' ), onChange: ( event ) => updateConfig( 'appearance.card_padding', event.target.value === '' ? null : Number( event.target.value ) ) } ) )\n\t\t\t\t\t\t)\n",
    )
    replace(
        path,
        "\t\t\t\t\th( ColorField, { label: __( 'Default Accent Colour', 'velox-map-locator' ), value: appearance.accent || '#2563eb', onChange: ( value ) => update( 'appearance.accent', value ) } )\n",
        "\t\t\t\t\th( 'div', { className: 'vml-field-grid two' },\n\t\t\t\t\t\th( ColorField, { label: __( 'Default Accent Colour', 'velox-map-locator' ), value: appearance.accent || '#2563eb', onChange: ( value ) => update( 'appearance.accent', value ) } ),\n\t\t\t\t\t\th( Field, { label: __( 'Default Card Padding', 'velox-map-locator' ), hint: __( '0–48 px. Leave blank to keep the current theme default for new Locators.', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 0, max: 48, value: appearance.card_padding ?? '', placeholder: __( 'Theme default', 'velox-map-locator' ), onChange: ( event ) => update( 'appearance.card_padding', event.target.value === '' ? null : Number( event.target.value ) ) } ) )\n\t\t\t\t\t)\n",
    )
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    text = text.replace("__( 'Automatic by visitor locale', 'velox-map-locator' )", "__( 'Automatic by site locale', 'velox-map-locator' )")
    text = text.replace(
        "__( 'Choose default theme, colour mode, typography, density and accent.', 'velox-map-locator' )",
        "__( 'Choose default theme, colour mode, typography, density, accent and optional card padding.', 'velox-map-locator' )",
    )
    file_path.write_text(text, encoding="utf-8")

# Apply a custom card-padding CSS variable only when the user explicitly supplies one.
for path in ("src/frontend/polish-1-1.js", "build/frontend-1-1.js"):
    replace(
        path,
        "\t\troot.style.setProperty( '--vml-shadow', shadows[ appearance.shadow ] || shadows.soft );\n\n\t\tconst accent = parseHex( appearance.accent );",
        "\t\troot.style.setProperty( '--vml-shadow', shadows[ appearance.shadow ] || shadows.soft );\n\n\t\tif ( appearance.card_padding !== null && appearance.card_padding !== undefined && appearance.card_padding !== '' ) {\n\t\t\tconst cardPadding = Math.max( 0, Math.min( 48, Number( appearance.card_padding ) || 0 ) );\n\t\t\troot.style.setProperty( '--vml-card-padding', `${ cardPadding }px` );\n\t\t} else {\n\t\t\troot.style.removeProperty( '--vml-card-padding' );\n\t\t}\n\n\t\tconst accent = parseHex( appearance.accent );",
    )

# Preserve today's compact defaults when padding is blank, and make active filter text white.
for path in ("src/styles/frontend-1-1.scss", "build/frontend-1-1.css"):
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    text = text.replace(
        '.vml-locator[data-vml11-enhanced="true"] .vml-location-card__body { padding: 15px 16px; }',
        '.vml-locator[data-vml11-enhanced="true"] .vml-location-card__body { padding: var(--vml-card-padding, 15px 16px); }',
        1,
    )
    text = text.replace(
        '.vml-locator[data-vml11-enhanced="true"].vml-locator--split .vml-location-card__body { padding: 13px 14px; }',
        '.vml-locator[data-vml11-enhanced="true"].vml-locator--split .vml-location-card__body { padding: var(--vml-card-padding, 13px 14px); }',
        1,
    )
    marker = '.vml-locator[data-vml11-enhanced="true"] .vml-locator__open-now:hover,\n.vml-locator[data-vml11-enhanced="true"] .vml-locator__open-now.is-selected,\n.vml-locator[data-vml11-enhanced="true"] .vml-locator__near.is-selected {'
    selected = '/* Selected filters always use a clear white foreground on the accent background. */\n.vml-locator[data-vml11-enhanced="true"] .vml-locator__pills button.is-selected,\n.vml-locator[data-vml11-enhanced="true"] .vml-locator__open-now.is-selected,\n.vml-locator[data-vml11-enhanced="true"] .vml-locator__near.is-selected {\n\tcolor: #fff !important;\n}\n'
    if marker not in text:
        raise RuntimeError(f"Selected-state target missing in {path}")
    text = text.replace(marker, selected + marker, 1)
    block_old = "\tbackground: var(--vml-accent) !important;\n\tborder-color: var(--vml-accent) !important;\n\tcolor: var(--vml-accent-contrast) !important;\n}"
    block_new = "\tbackground: var(--vml-accent) !important;\n\tborder-color: var(--vml-accent) !important;\n\tcolor: #fff !important;\n}"
    if block_old not in text:
        raise RuntimeError(f"Accent state target missing in {path}")
    file_path.write_text(text.replace(block_old, block_new, 1), encoding="utf-8")

# Use a target/crosshair for Fit All; Fullscreen keeps its corner-expansion icon.
old_icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 4H4v4"/><path d="M16 4h4v4"/><path d="M20 16v4h-4"/><path d="M4 16v4h4"/></svg>'
new_icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4.5"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/></svg>'
for path in (
    "src/frontend/map-leaflet.js",
    "build/map-leaflet.js",
    "src/frontend/map-google.js",
    "build/map-google.js",
):
    replace(path, old_icon, new_icon)

# Keep source/build JS pairs synchronized.
assert (ROOT / "src/frontend/map-leaflet.js").read_text(encoding="utf-8") == (ROOT / "build/map-leaflet.js").read_text(encoding="utf-8")
assert (ROOT / "src/frontend/map-google.js").read_text(encoding="utf-8") == (ROOT / "build/map-google.js").read_text(encoding="utf-8")
assert (ROOT / "src/frontend/polish-1-1.js").read_text(encoding="utf-8") == (ROOT / "build/frontend-1-1.js").read_text(encoding="utf-8")
source_admin = (ROOT / "src/admin/index.js").read_text(encoding="utf-8").splitlines(keepends=True)[1:]
assert "".join(source_admin) == (ROOT / "build/admin.js").read_text(encoding="utf-8")
