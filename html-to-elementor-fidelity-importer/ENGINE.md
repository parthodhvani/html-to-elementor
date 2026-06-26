# Visual Reconstruction Engine v2

The v2 engine transforms the plugin from an HTML importer into a **Visual
Reconstruction Engine**. The rendered page — not the raw DOM — is the primary
source of truth.

## Pipeline

```
Rendered Page (Chromium)
        ↓
Visual Tree Extraction
        ↓
Wrapper Elimination
        ↓
Semantic Layout Graph
        ↓
Design Token Extraction
        ↓
Component Recognition (confidence-based)
        ↓
Native Widget Mapping + CSS Mapping
        ↓
Responsive Reconstruction
        ↓
Elementor JSON Generation
        ↓
Visual Validation
        ↓
Automatic Repair (iterative)
```

## Conversion modes

| Mode | Class | Description |
|------|-------|-------------|
| `preserve` | `ElementorJsonGenerator` | Legacy fidelity mode — HTML widgets per section |
| `widgets` | `ElementorJsonGenerator` | Conservative single-widget detection |
| `reconstruct` | `VisualReconstructionEngine` | **v2 native widget reconstruction** |

Set via settings (`conversion_mode`) or per-request overrides (REST, WP-CLI).

## Node engines (`chromium-service/lib/`)

| File | Engine | Responsibility |
|------|--------|----------------|
| `visual-extractor.js` | Chromium Visual Extraction | Full visual tree: bbox, computed styles, CSS vars, pseudo elements, stacking context, transforms, media detection, DOM path, XPath |
| `wrapper-eliminator.js` | Wrapper Elimination | Removes pass-through wrappers with no visual identity |
| `layout-graph.js` | Layout Graph | Infers sections, rows, columns, grids, heroes, CTAs, cards, etc. from geometry |
| `design-token-extractor.js` | Design Tokens | Detects color, typography, spacing, radius, shadow scales |
| `extractor.js` | Orchestrator | v2 layout document with 7 responsive breakpoints |

## PHP engines (`includes/Engine/`)

| Namespace | Class | Responsibility |
|-----------|-------|----------------|
| `Engine\Design` | `DesignTokenExtractor` | Normalizes tokens → Elementor global style candidates |
| `Engine\Layout` | `LayoutGraphEngine` | Region traversal and container/leaf decisions |
| `Engine\Layout` | `ConstraintLayoutEngine` | Figma Auto Layout-style gap, padding, flex constraints |
| `Engine\Layout` | `WrapperEliminator` | PHP-side pass-through region hoisting |
| `Engine\Recognition` | `ComponentRecognitionEngine` | Confidence-based classifier (visual + geometry + context) |
| `Engine\Mapping` | `NativeWidgetMapper` | Maps recognition → Elementor widgets; HTML is last resort |
| `Engine\Mapping` | `CssMappingEngine` | Computed CSS → Elementor controls |
| `Engine\Responsive` | `ResponsiveReconstructionEngine` | Multi-breakpoint Elementor responsive controls |
| `Engine\Media` | `MediaEngine` | Image/SVG/WebP import to Media Library |
| `Engine\Animation` | `AnimationEngine` | CSS transitions/transforms → Elementor Motion Effects |
| `Engine\Validation` | `VisualValidationEngine` | Fidelity scoring (SSIM, perceptual hash, layout metrics) |
| `Engine\Validation` | `FidelityComparator` | Screenshot and layout comparison utilities |
| `Engine\Repair` | `AutomaticRepairEngine` | Iterative JSON repair when fidelity < threshold |
| `Engine\Reconstruction` | `VisualReconstructionEngine` | Primary v2 orchestrator |

## Layout document v2 schema

Key fields added to `layout.json`:

- `version: 2`
- `visualTree` — full extracted visual tree
- `layoutGraph` — semantic region hierarchy
- `designTokens` — color/typography/spacing scales
- `sections` — backward-compatible top-level regions (now typed: hero, grid, cta, etc.)
- `stats` — node count, wrappers removed, region count

## Settings

| Key | Default | Description |
|-----|---------|-------------|
| `conversion_mode` | `preserve` | `preserve`, `widgets`, or `reconstruct` |
| `widget_confidence` | `90` | Minimum classifier confidence (0–100) |
| `fidelity_threshold` | `95` | Target overall fidelity score |
| `max_repair_iterations` | `3` | Automatic repair loop limit |
| `engine_version` | `2` | Extraction engine version |

## Self-improving import report

Every `reconstruct` import emits extended metrics:

- Visual Fidelity Score
- Layout / Typography / Spacing scores
- Widget Coverage
- Native vs HTML widget percentages
- Missing assets
- Unsupported CSS properties
- Repair iteration count

## Quick test (no WordPress)

```bash
cd html-to-elementor-fidelity-importer
node chromium-service/cli.js --input tests/fixtures/sample.html --out /tmp/layout.json
php tests/harness.php /tmp/layout.json reconstruct
./vendor/bin/phpunit
```

## Backward compatibility

- `preserve` and `widgets` modes unchanged
- Existing REST API, WP-CLI commands, and settings keys preserved
- v1 layout fixtures still work (PHP engines fall back to section-based extraction)
