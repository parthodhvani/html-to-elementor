'use strict';

/**
 * Chromium Visual Extraction Engine (v2).
 *
 * Extracts a comprehensive visual tree from the rendered page. DOM is
 * supporting metadata — geometry, computed styles, and visual relationships
 * are the primary source of truth.
 */

const VISUAL_PROPS = [
  'display', 'position', 'top', 'right', 'bottom', 'left', 'zIndex',
  'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'alignContent',
  'gap', 'rowGap', 'columnGap', 'gridTemplateColumns', 'gridTemplateRows',
  'gridColumn', 'gridRow', 'order', 'flexGrow', 'flexShrink', 'flexBasis',
  'backgroundColor', 'backgroundImage', 'backgroundSize', 'backgroundPosition',
  'backgroundRepeat', 'color', 'fontFamily', 'fontSize', 'fontWeight',
  'fontStyle', 'lineHeight', 'letterSpacing', 'textAlign', 'textTransform',
  'textDecoration', 'whiteSpace', 'wordBreak', 'writingMode',
  'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
  'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
  'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
  'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor',
  'borderTopStyle', 'borderRadius', 'borderTopLeftRadius', 'borderTopRightRadius',
  'borderBottomLeftRadius', 'borderBottomRightRadius',
  'boxShadow', 'opacity', 'filter', 'transform', 'transformOrigin',
  'clipPath', 'maskImage', 'overflow', 'overflowX', 'overflowY',
  'objectFit', 'aspectRatio', 'minWidth', 'minHeight', 'maxWidth', 'maxHeight',
  'width', 'height', 'visibility', 'pointerEvents', 'cursor',
  'content', 'listStyleType', 'textOverflow', 'verticalAlign',
];

const PSEUDO_STATES = ['', ':hover', ':focus', ':active'];

/**
 * Build the full visual tree rooted at document.body.
 * Executed inside Chromium via page.evaluate().
 *
 * @returns {object}
 */
function browserVisualExtractor() {
  const VISUAL_PROPS = [
    'display', 'position', 'top', 'right', 'bottom', 'left', 'zIndex',
    'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'alignContent',
    'gap', 'rowGap', 'columnGap', 'gridTemplateColumns', 'gridTemplateRows',
    'gridColumn', 'gridRow', 'order', 'flexGrow', 'flexShrink', 'flexBasis',
    'backgroundColor', 'backgroundImage', 'backgroundSize', 'backgroundPosition',
    'backgroundRepeat', 'color', 'fontFamily', 'fontSize', 'fontWeight',
    'fontStyle', 'lineHeight', 'letterSpacing', 'textAlign', 'textTransform',
    'textDecoration', 'whiteSpace', 'wordBreak', 'writingMode',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
    'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
    'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
    'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor',
    'borderTopStyle', 'borderRadius', 'borderTopLeftRadius', 'borderTopRightRadius',
    'borderBottomLeftRadius', 'borderBottomRightRadius',
    'boxShadow', 'opacity', 'filter', 'transform', 'transformOrigin',
    'clipPath', 'maskImage', 'overflow', 'overflowX', 'overflowY',
    'objectFit', 'aspectRatio', 'minWidth', 'minHeight', 'maxWidth', 'maxHeight',
    'width', 'height', 'visibility', 'pointerEvents', 'cursor',
    'content', 'listStyleType', 'textOverflow', 'verticalAlign', 'transition',
  ];

  const PSEUDO_STATES = ['', ':hover', ':focus', ':active'];

  let uidCounter = 0;

  function nextId() {
    uidCounter += 1;
    return 'v' + uidCounter;
  }

  function isVisible(el) {
    if (!(el instanceof Element)) return false;
    const cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) {
      return false;
    }
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function domPath(el) {
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1 && node !== document.documentElement) {
      let part = node.tagName.toLowerCase();
      if (node.id) part += '#' + node.id;
      else if (node.className && typeof node.className === 'string') {
        const cls = node.className.trim().split(/\s+/).slice(0, 2).join('.');
        if (cls) part += '.' + cls;
      }
      parts.unshift(part);
      node = node.parentElement;
    }
    return parts.join(' > ');
  }

  function xpath(el) {
    if (!el || el.nodeType !== 1) return '';
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1) {
      let idx = 1;
      let sib = node.previousElementSibling;
      while (sib) {
        if (sib.tagName === node.tagName) idx += 1;
        sib = sib.previousElementSibling;
      }
      parts.unshift(node.tagName.toLowerCase() + '[' + idx + ']');
      node = node.parentElement;
    }
    return '/' + parts.join('/');
  }

  function resolveUrls(value, base) {
    if (!value || typeof value !== 'string') return value;
    return value.replace(/url\(["']?([^"')]+)["']?\)/g, (m, u) => {
      try {
        return 'url("' + new URL(u, base).href + '")';
      } catch (e) {
        return m;
      }
    });
  }

  function captureStyles(el, baseUrl) {
    const cs = window.getComputedStyle(el);
    const styles = {};
    VISUAL_PROPS.forEach((p) => {
      let val = cs.getPropertyValue(
        p.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())
      ) || cs[p];
      if (typeof val === 'string' && val.indexOf('url(') !== -1) {
        val = resolveUrls(val, baseUrl);
      }
      styles[p] = val;
    });

    const cssVars = {};
    for (let i = 0; i < cs.length; i += 1) {
      const prop = cs[i];
      if (prop.startsWith('--')) {
        cssVars[prop] = cs.getPropertyValue(prop).trim();
      }
    }

    return { styles, cssVars };
  }

  function capturePseudo(el) {
    const out = {};
    for (const state of PSEUDO_STATES) {
      if (state === '') continue;
      try {
        const cs = window.getComputedStyle(el, state);
        out[state] = {
          content: cs.content,
          display: cs.display,
          opacity: cs.opacity,
          color: cs.color,
          backgroundColor: cs.backgroundColor,
        };
      } catch (e) {
        /* unsupported pseudo */
      }
    }
    return out;
  }

  function stackingContext(el, cs) {
    const pos = cs.position;
    const z = parseInt(cs.zIndex, 10);
    return (
      (pos === 'absolute' || pos === 'relative' || pos === 'fixed' || pos === 'sticky') &&
      !Number.isNaN(z)
    ) || parseFloat(cs.opacity) < 1 || cs.transform !== 'none' || cs.filter !== 'none';
  }

  function scrollContainer(el, cs) {
    return (
      cs.overflow === 'auto' || cs.overflow === 'scroll' ||
      cs.overflowY === 'auto' || cs.overflowY === 'scroll' ||
      cs.overflowX === 'auto' || cs.overflowX === 'scroll'
    );
  }

  function detectMedia(el, tag) {
    const t = tag.toLowerCase();
    return {
      isImage: t === 'img',
      isSvg: t === 'svg' || el instanceof SVGElement,
      isCanvas: t === 'canvas',
      isVideo: t === 'video' || t === 'iframe',
      isPicture: t === 'picture',
    };
  }

  function lazyState(el) {
    const loading = el.getAttribute('loading');
    const complete = el.complete !== undefined ? el.complete : null;
    const inView = el.getBoundingClientRect().top < window.innerHeight;
    return { loading, complete, inView };
  }

  function extractNode(el, parentId, siblingIndex) {
    if (!isVisible(el)) return null;

    const id = nextId();
    el.setAttribute('data-h2e-vid', id);
    const rect = el.getBoundingClientRect();
    const tag = el.tagName.toLowerCase();
    const { styles, cssVars } = captureStyles(el, location.href);
    const cs = window.getComputedStyle(el);
    const media = detectMedia(el, tag);

    const node = {
      id,
      parentId,
      siblingIndex,
      tag,
      domId: el.id || '',
      classes: el.className && typeof el.className === 'string' ? el.className : '',
      role: el.getAttribute('role') || '',
      ariaLabel: el.getAttribute('aria-label') || '',
      tabIndex: el.tabIndex,
      domPath: domPath(el),
      xpath: xpath(el),
      bbox: {
        x: rect.x, y: rect.y,
        width: rect.width, height: rect.height,
        top: rect.top, left: rect.left,
        right: rect.right, bottom: rect.bottom,
      },
      styles,
      cssVars,
      pseudo: capturePseudo(el),
      pseudoElements: {
        before: window.getComputedStyle(el, '::before').content,
        after: window.getComputedStyle(el, '::after').content,
      },
      stackingContext: stackingContext(el, cs),
      scrollContainer: scrollContainer(el, cs),
      media,
      lazy: media.isImage ? lazyState(el) : null,
      src: el.getAttribute('src') || el.getAttribute('href') || '',
      alt: el.getAttribute('alt') || '',
      text: el.childNodes.length === 1 && el.childNodes[0].nodeType === 3
        ? (el.textContent || '').trim()
        : '',
      innerTextSample: (el.innerText || '').trim().slice(0, 200),
      childCount: el.children.length,
      html: el.outerHTML.slice(0, 8000),
      children: [],
    };

    let sib = 0;
    for (const child of Array.from(el.children)) {
      const extracted = extractNode(child, id, sib);
      if (extracted) {
        node.children.push(extracted);
        sib += 1;
      }
    }

    return node;
  }

  const root = extractNode(document.body, null, 0);
  return {
    root,
    viewport: { width: window.innerWidth, height: window.innerHeight },
    scroll: { x: window.scrollX, y: window.scrollY },
    nodeCount: uidCounter,
  };
}

/**
 * Flatten visual tree into an indexed map for fast lookup.
 *
 * @param {object} tree Visual tree root wrapper.
 * @returns {Object<string,object>}
 */
function indexVisualTree(tree) {
  const index = {};
  function walk(node) {
    if (!node) return;
    index[node.id] = node;
    (node.children || []).forEach(walk);
  }
  walk(tree.root);
  return index;
}

module.exports = { browserVisualExtractor, indexVisualTree, VISUAL_PROPS };
