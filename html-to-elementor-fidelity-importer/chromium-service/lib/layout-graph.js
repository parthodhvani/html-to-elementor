'use strict';

/**
 * Layout Graph Engine.
 *
 * Reconstructs visual hierarchy from the visual tree using alignment, spacing,
 * bounding boxes, and gestalt principles. Produces a semantic layout graph
 * with inferred regions (sections, rows, columns, cards, heroes, etc.).
 */

const REGION_TYPES = [
  'section', 'row', 'column', 'stack', 'card', 'grid', 'hero', 'cta',
  'navigation', 'sidebar', 'footer', 'timeline', 'pricing', 'testimonial',
  'feature_grid', 'faq', 'gallery', 'form', 'media', 'content_group',
  'whitespace', 'heading', 'text', 'button', 'image', 'list', 'divider',
  'unknown',
];

/**
 * Parse a CSS pixel value.
 *
 * @param {string|number} v Value.
 * @returns {number}
 */
function px(v) {
  if (typeof v === 'number') return v;
  const m = String(v || '').match(/(-?\d+(\.\d+)?)/);
  return m ? parseFloat(m[1]) : 0;
}

/**
 * Infer flex/grid layout direction from children bboxes.
 *
 * @param {Array<object>} children Child visual nodes.
 * @returns {'row'|'column'}
 */
function inferDirection(children) {
  if (children.length < 2) return 'column';
  const sortedX = [...children].sort((a, b) => (a.bbox?.x || 0) - (b.bbox?.x || 0));
  const sortedY = [...children].sort((a, b) => (a.bbox?.y || 0) - (b.bbox?.y || 0));
  const xSpread = Math.abs((sortedX[sortedX.length - 1].bbox?.x || 0) - (sortedX[0].bbox?.x || 0));
  const ySpread = Math.abs((sortedY[sortedY.length - 1].bbox?.y || 0) - (sortedY[0].bbox?.y || 0));
  return xSpread > ySpread ? 'row' : 'column';
}

/**
 * Compute average gap between sibling bboxes.
 *
 * @param {Array<object>} children Children sorted along axis.
 * @param {'row'|'column'} axis Layout axis.
 * @returns {number}
 */
function averageGap(children, axis) {
  if (children.length < 2) return 0;
  const sorted = [...children].sort((a, b) => {
    if (axis === 'row') return (a.bbox?.x || 0) - (b.bbox?.x || 0);
    return (a.bbox?.y || 0) - (b.bbox?.y || 0);
  });
  const gaps = [];
  for (let i = 1; i < sorted.length; i += 1) {
    const prev = sorted[i - 1].bbox || {};
    const curr = sorted[i].bbox || {};
    if (axis === 'row') {
      gaps.push(Math.max(0, (curr.x || 0) - ((prev.x || 0) + (prev.width || 0))));
    } else {
      gaps.push(Math.max(0, (curr.y || 0) - ((prev.y || 0) + (prev.height || 0))));
    }
  }
  return gaps.length ? gaps.reduce((a, b) => a + b, 0) / gaps.length : 0;
}

/**
 * Classify a visual node into a semantic region type.
 *
 * @param {object} node Visual tree node.
 * @param {object} context Parent context.
 * @returns {string}
 */
function classifyRegion(node, context = {}) {
  const tag = (node.tag || '').toLowerCase();
  const cls = (node.classes || '').toLowerCase();
  const role = (node.role || '').toLowerCase();
  const text = (node.innerTextSample || '').toLowerCase();
  const s = node.styles || {};
  const display = s.display || '';
  const childCount = (node.children || []).length;

  if (tag === 'header' || cls.includes('hero') || role === 'banner') return 'hero';
  if (tag === 'nav' || role === 'navigation' || cls.includes('nav')) return 'navigation';
  if (tag === 'footer' || role === 'contentinfo') return 'footer';
  if (tag === 'aside' || cls.includes('sidebar')) return 'sidebar';
  if (tag === 'form' || role === 'form') return 'form';
  if (cls.includes('cta') || cls.includes('call-to-action')) return 'cta';
  if (cls.includes('pricing') || cls.includes('price')) return 'pricing';
  if (cls.includes('testimonial')) return 'testimonial';
  if (cls.includes('faq')) return 'faq';
  if (cls.includes('gallery') || cls.includes('carousel')) return 'gallery';
  if (cls.includes('timeline')) return 'timeline';
  if (cls.includes('card')) return 'card';
  if (cls.includes('feature')) return 'feature_grid';

  if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tag)) return 'heading';
  if (tag === 'p') return 'text';
  if (tag === 'img' || tag === 'picture' || tag === 'svg') return 'image';
  if (tag === 'button' || (tag === 'a' && (cls.includes('btn') || cls.includes('button')))) return 'button';
  if (tag === 'ul' || tag === 'ol') return 'list';
  if (tag === 'hr') return 'divider';
  if (tag === 'video' || tag === 'iframe') return 'media';

  if (display === 'grid' || (display === 'flex' && childCount >= 3 &&
    inferDirection(node.children) === 'row')) return 'grid';
  if (display === 'flex' && childCount >= 2) {
    return inferDirection(node.children) === 'row' ? 'row' : 'stack';
  }
  if (['section', 'article', 'main'].includes(tag)) return 'section';
  if (childCount === 0 && !text) return 'whitespace';
  if (childCount > 0) return 'content_group';

  return 'unknown';
}

/**
 * Build a layout graph node from a visual tree node.
 *
 * @param {object} node Visual node.
 * @param {number} depth Recursion depth.
 * @returns {object}
 */
function buildGraphNode(node, depth = 0) {
  const children = (node.children || [])
    .filter((c) => (c.bbox?.height || 0) > 1 && (c.bbox?.width || 0) > 1)
    .map((c) => buildGraphNode(c, depth + 1));

  const direction = children.length >= 2 ? inferDirection(children) : 'column';
  const gap = averageGap(children, direction);
  const type = classifyRegion(node, { depth, childCount: children.length });

  return {
    id: node.id,
    type,
    tag: node.tag,
    domId: node.domId,
    classes: node.classes,
    role: node.role,
    bbox: node.bbox,
    styles: node.styles,
    cssVars: node.cssVars,
    html: node.html,
    text: node.text || node.innerTextSample,
    src: node.src,
    alt: node.alt,
    media: node.media,
    layout: {
      direction,
      gap: Math.round(gap),
      align: node.styles?.alignItems || 'stretch',
      justify: node.styles?.justifyContent || 'flex-start',
      display: node.styles?.display || 'block',
    },
    constraints: {
      padding: {
        top: px(node.styles?.paddingTop),
        right: px(node.styles?.paddingRight),
        bottom: px(node.styles?.paddingBottom),
        left: px(node.styles?.paddingLeft),
      },
      margin: {
        top: px(node.styles?.marginTop),
        right: px(node.styles?.marginRight),
        bottom: px(node.styles?.marginBottom),
        left: px(node.styles?.marginLeft),
      },
    },
    children,
    depth,
    visualNodeId: node.id,
  };
}

/**
 * Extract top-level page regions for backward-compatible sections array.
 *
 * @param {object} graph Root layout graph node.
 * @returns {Array<object>}
 */
function graphToSections(graph) {
  const regions = graph.children && graph.children.length ? graph.children : [graph];
  return regions.map((region, index) => ({
    index,
    tag: region.tag || 'div',
    id: region.domId || '',
    classes: region.classes || '',
    semantic: ['section', 'hero', 'navigation', 'footer'].includes(region.type),
    type: region.type,
    html: region.html || '',
    bbox: region.bbox || {},
    styles: region.styles || {},
    background: region.styles?.backgroundColor || '',
    layout: region.layout,
    constraints: region.constraints,
    graphId: region.id,
    responsive: {},
  }));
}

/**
 * Build the full layout graph from a visual tree root.
 *
 * @param {object} visualTree Visual tree document.
 * @returns {object}
 */
function buildLayoutGraph(visualTree) {
  const root = visualTree.root;
  if (!root) {
    return { root: null, sections: [], regionTypes: REGION_TYPES };
  }

  const graphRoot = buildGraphNode(root);
  const sections = graphToSections(graphRoot);

  return {
    root: graphRoot,
    sections,
    regionTypes: REGION_TYPES,
    stats: {
      totalRegions: countRegions(graphRoot),
      maxDepth: maxDepth(graphRoot),
    },
  };
}

function countRegions(node) {
  if (!node) return 0;
  return 1 + (node.children || []).reduce((s, c) => s + countRegions(c), 0);
}

function maxDepth(node, d = 0) {
  if (!node) return d;
  const childDepths = (node.children || []).map((c) => maxDepth(c, d + 1));
  return childDepths.length ? Math.max(...childDepths) : d;
}

module.exports = {
  buildLayoutGraph,
  graphToSections,
  classifyRegion,
  inferDirection,
  averageGap,
  REGION_TYPES,
};
