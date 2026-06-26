'use strict';

/**
 * Wrapper Elimination Engine.
 *
 * Removes meaningless wrapper nodes that exist only for DOM structure but
 * carry no independent visual identity. Operates on the visual tree using
 * geometry, styles, and child relationships — never mirrors raw DOM.
 */

const PASS_THROUGH_DISPLAY = new Set(['block', 'flex', 'grid', 'flow-root', 'contents']);

/**
 * Whether a node is a meaningless visual wrapper.
 *
 * @param {object} node Visual tree node.
 * @returns {boolean}
 */
function isMeaninglessWrapper(node) {
  if (!node || !node.children || node.children.length !== 1) return false;

  const tag = (node.tag || '').toLowerCase();
  const semantic = ['header', 'nav', 'main', 'section', 'article', 'aside', 'footer', 'form'];
  if (semantic.includes(tag)) return false;
  if (node.role) return false;
  if (node.media && (node.media.isImage || node.media.isVideo || node.media.isSvg)) return false;

  const s = node.styles || {};
  const display = s.display || '';
  if (!PASS_THROUGH_DISPLAY.has(display)) return false;

  const hasBg = s.backgroundColor && s.backgroundColor !== 'rgba(0, 0, 0, 0)' &&
    s.backgroundColor !== 'transparent';
  const hasBgImg = s.backgroundImage && s.backgroundImage !== 'none';
  const hasBorder = parseFloat(s.borderTopWidth || 0) > 0;
  const hasShadow = s.boxShadow && s.boxShadow !== 'none';
  const hasTransform = s.transform && s.transform !== 'none';
  const hasOpacity = parseFloat(s.opacity || 1) < 1;
  const hasPadding = ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft']
    .some((k) => parseFloat(s[k] || 0) > 0);
  const hasMargin = ['marginTop', 'marginRight', 'marginBottom', 'marginLeft']
    .some((k) => parseFloat(s[k] || 0) > 0);

  if (hasBg || hasBgImg || hasBorder || hasShadow || hasTransform || hasOpacity) return false;
  if (hasPadding || hasMargin) return false;
  if ((node.innerTextSample || '').trim()) return false;

  const child = node.children[0];
  const childBbox = child.bbox || {};
  const nodeBbox = node.bbox || {};
  const overlap = Math.abs((childBbox.width || 0) - (nodeBbox.width || 0)) < 2 &&
    Math.abs((childBbox.height || 0) - (nodeBbox.height || 0)) < 2;

  return overlap;
}

/**
 * Hoist children through meaningless wrappers recursively.
 *
 * @param {object} node Visual tree node.
 * @returns {object}
 */
function eliminateWrappers(node) {
  if (!node) return node;

  let children = (node.children || []).map(eliminateWrappers);

  while (children.length === 1 && isMeaninglessWrapper({ ...node, children })) {
    const only = children[0];
    children = (only.children || []).map(eliminateWrappers);
  }

  return { ...node, children };
}

/**
 * Count wrappers removed during elimination.
 *
 * @param {object} before Tree before.
 * @param {object} after  Tree after.
 * @returns {number}
 */
function countRemoved(before, after) {
  function count(n) {
    if (!n) return 0;
    return 1 + (n.children || []).reduce((s, c) => s + count(c), 0);
  }
  return Math.max(0, count(before) - count(after));
}

module.exports = { isMeaninglessWrapper, eliminateWrappers, countRemoved };
