'use strict';

/**
 * Section segmentation engine.
 *
 * `browserPageSegmenter` is serialised and executed inside Chromium via
 * page.evaluate(), so it must be fully self-contained (no outer references).
 *
 * Strategy (visual + semantic, never altering internal structure):
 *   1. Descend through single full-width wrapper elements to find the real
 *      content container (e.g. <body> > <div id="app"> > ...).
 *   2. Treat each visible direct child of that container as a section boundary.
 *   3. Always treat semantic landmarks (header/nav/main/section/article/
 *      aside/footer) as their own sections.
 *   4. Tag each section with data-h2e-section and capture its outerHTML, box
 *      model and key computed styles. Internal markup is never modified.
 */

/**
 * The in-page segmenter. Returns an array of section descriptors.
 *
 * @returns {Array<object>}
 */
function browserPageSegmenter() {
  const SEMANTIC = ['HEADER', 'NAV', 'MAIN', 'SECTION', 'ARTICLE', 'ASIDE', 'FOOTER'];
  const CAPTURED_PROPS = [
    'display', 'position', 'backgroundColor', 'backgroundImage', 'color',
    'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight',
    'marginTop', 'marginBottom', 'flexDirection', 'justifyContent',
    'alignItems', 'textAlign', 'fontSize', 'fontFamily', 'lineHeight',
    'borderTopWidth', 'borderBottomWidth', 'minHeight',
  ];

  function isVisible(el) {
    if (!(el instanceof Element)) return false;
    const cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) {
      return false;
    }
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function visibleChildren(el) {
    return Array.from(el.children).filter(isVisible);
  }

  function isSemantic(el) {
    return SEMANTIC.indexOf(el.tagName) !== -1;
  }

  // 1. Find the content container by descending single non-semantic wrappers.
  let container = document.body;
  let guard = 0;
  while (guard < 10) {
    guard += 1;
    const kids = visibleChildren(container);
    if (kids.length === 1 && !isSemantic(kids[0]) && kids[0].children.length > 0) {
      container = kids[0];
    } else {
      break;
    }
  }

  // 2 + 3. Section candidates = visible direct children of the container.
  let candidates = visibleChildren(container);
  if (candidates.length === 0) {
    candidates = [container];
  }

  // 4. Capture each section.
  const sections = [];
  candidates.forEach((el, index) => {
    el.setAttribute('data-h2e-section', String(index));
    const rect = el.getBoundingClientRect();
    const cs = window.getComputedStyle(el);
    const styles = {};
    CAPTURED_PROPS.forEach((p) => {
      styles[p] = cs[p];
    });

    sections.push({
      index,
      tag: el.tagName.toLowerCase(),
      id: el.id || '',
      classes: el.className && typeof el.className === 'string' ? el.className : '',
      semantic: isSemantic(el),
      html: el.outerHTML,
      bbox: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
      styles,
      background: cs.backgroundColor,
    });
  });

  return sections;
}

module.exports = { browserPageSegmenter };
