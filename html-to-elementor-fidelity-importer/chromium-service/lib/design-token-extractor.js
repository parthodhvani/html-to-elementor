'use strict';

/**
 * Design Token Extractor.
 *
 * Detects repeated colors, typography, spacing, radius, and shadows from the
 * visual tree and produces normalized design token scales.
 */

/**
 * @param {object} visualTree Visual tree document.
 * @returns {object}
 */
function extractDesignTokens(visualTree) {
  const colors = new Map();
  const fonts = new Map();
  const fontSizes = new Map();
  const spacing = new Map();
  const radii = new Map();
  const shadows = new Map();
  const widths = new Map();

  function add(map, key, weight = 1) {
    if (!key || key === 'transparent' || key === 'none' || key === '0px') return;
    map.set(key, (map.get(key) || 0) + weight);
  }

  function walk(node) {
    if (!node) return;
    const s = node.styles || {};
    add(colors, s.color, 1);
    add(colors, s.backgroundColor, 2);
    add(fonts, s.fontFamily, 1);
    add(fontSizes, s.fontSize, 1);
    ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
      'marginTop', 'marginRight', 'marginBottom', 'marginLeft', 'gap'].forEach((k) => {
      add(spacing, s[k], 1);
    });
    add(radii, s.borderRadius, 1);
    add(shadows, s.boxShadow, 1);
    if (node.bbox?.width) add(widths, String(Math.round(node.bbox.width)), 1);
    (node.children || []).forEach(walk);
  }

  walk(visualTree.root);

  const sorted = (map) => [...map.entries()].sort((a, b) => b[1] - a[1]).map(([v]) => v);

  const palette = sorted(colors).slice(0, 12);
  return {
    colors: {
      primary: palette[0] || null,
      secondary: palette[1] || null,
      accent: palette[2] || null,
      neutral: palette.slice(3, 8),
      all: palette,
    },
    typography: {
      families: sorted(fonts).slice(0, 5),
      scale: sorted(fontSizes).slice(0, 8),
    },
    spacing: {
      scale: sorted(spacing).slice(0, 10).map((v) => parseFloat(v) || 0).filter((v) => v > 0),
    },
    radius: {
      scale: sorted(radii).slice(0, 6),
    },
    shadows: {
      scale: sorted(shadows).slice(0, 5),
    },
    containers: {
      widths: sorted(widths).slice(0, 5).map((v) => parseInt(v, 10)),
    },
  };
}

module.exports = { extractDesignTokens };
