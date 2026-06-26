'use strict';

/**
 * Chromium rendering + visual extraction engine (v2).
 *
 * Pipeline:
 *   Rendered Page -> Visual Tree -> Layout Graph -> Sections (compat)
 *
 * DOM is supporting metadata; visual geometry and computed styles are primary.
 */

const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
const puppeteer = require('puppeteer');

const { browserVisualExtractor } = require('./visual-extractor');
const { eliminateWrappers, countRemoved } = require('./wrapper-eliminator');
const { buildLayoutGraph } = require('./layout-graph');
const { extractDesignTokens } = require('./design-token-extractor');
const { browserPageSegmenter } = require('./segmenter');

const RESPONSIVE_WIDTHS = {
  w1920: 1920,
  w1440: 1440,
  w1280: 1280,
  w1024: 1024,
  w768: 768,
  w480: 480,
  w375: 375,
};

const DEFAULT_CONFIG = {
  breakpoints: {
    desktop: 1280,
    tablet: 768,
    mobile: 375,
    ...RESPONSIVE_WIDTHS,
  },
  waitUntil: 'networkidle0',
  timeout: 60000,
  captureScreenshots: true,
  conversionMode: 'preserve',
  widgetConfidence: 95,
  fidelityThreshold: 95,
  maxRepairIterations: 3,
  debug: false,
  engineVersion: 2,
};

/**
 * Render and extract a v2 layout document from an HTML entry file.
 *
 * @param {string} inputPath Absolute path to the entry .html file.
 * @param {string} outDir    Directory for screenshots and artifacts.
 * @param {object} userConfig Partial configuration overrides.
 * @returns {Promise<object>} The layout document.
 */
async function renderToLayout(inputPath, outDir, userConfig = {}) {
  const config = { ...DEFAULT_CONFIG, ...userConfig };
  config.breakpoints = { ...DEFAULT_CONFIG.breakpoints, ...(userConfig.breakpoints || {}) };

  if (!fs.existsSync(inputPath)) {
    throw new Error(`Input file not found: ${inputPath}`);
  }
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await puppeteer.launch({
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--font-render-hinting=none',
    ],
  });

  try {
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(config.timeout);
    page.setDefaultTimeout(config.timeout);

    const fileUrl = pathToFileURL(inputPath).href;
    const desktopWidth = config.breakpoints.desktop || 1280;

    await page.setViewport({ width: desktopWidth, height: 900, deviceScaleFactor: 1 });
    await page.goto(fileUrl, { waitUntil: config.waitUntil, timeout: config.timeout });
    await waitForStable(page, config);

    const meta = await page.evaluate(() => ({
      title: document.title || '',
      url: location.href,
      width: document.documentElement.scrollWidth,
      height: document.documentElement.scrollHeight,
    }));

    const assets = await page.evaluate(extractAssetsInPage);

    // v2: Full visual tree extraction.
    let visualTree = await page.evaluate(browserVisualExtractor);
    const wrappersRemoved = countRemoved(visualTree.root, eliminateWrappers(visualTree.root).root);
    visualTree = {
      ...visualTree,
      root: eliminateWrappers(visualTree.root),
      wrappersRemoved,
    };

    const layoutGraph = buildLayoutGraph(visualTree);
    const designTokens = extractDesignTokens(visualTree);

    // Backward-compatible section tagging for responsive re-measurement.
    let sections = layoutGraph.sections;
    await page.evaluate(tagSectionsFromGraph, sections.map((s) => s.graphId));

    const responsive = { desktop: indexBySection(sections) };
    const screenshots = {};

    if (config.captureScreenshots) {
      screenshots.desktop = await screenshot(page, outDir, 'desktop');
    }

    const measureKeys = ['tablet', 'mobile', 'w1920', 'w1440', 'w1024', 'w768', 'w480', 'w375'];
    for (const device of measureKeys) {
      const width = config.breakpoints[device];
      if (!width) continue;
      await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
      await sleep(300);
      await waitForStable(page, config);
      responsive[device] = await page.evaluate(measureTaggedSections);
      if (config.captureScreenshots && ['tablet', 'mobile', 'w768', 'w375'].includes(device)) {
        screenshots[device] = await screenshot(page, outDir, device);
      }
    }

    sections = sections.map((section) => {
      const idx = section.index;
      const resp = { desktop: responsive.desktop[idx] || null };
      for (const device of measureKeys) {
        resp[device] = (responsive[device] || {})[idx] || null;
      }
      return { ...section, responsive: resp };
    });

    // Legacy segmenter output for preserve-mode regression tests.
    const legacySections = await page.evaluate(browserPageSegmenter);

    return {
      version: 2,
      meta: { ...meta, generatedAt: new Date().toISOString(), engineVersion: 2 },
      breakpoints: config.breakpoints,
      screenshots,
      assets,
      visualTree,
      layoutGraph,
      designTokens,
      sections,
      legacySections: legacySections.map(stripMarkers),
      stats: {
        nodeCount: visualTree.nodeCount,
        wrappersRemoved,
        regionCount: layoutGraph.stats?.totalRegions || 0,
      },
    };
  } finally {
    await browser.close();
  }
}

async function waitForStable(page, config) {
  try {
    await page.evaluate(async () => {
      if (document.fonts && document.fonts.ready) await document.fonts.ready;
      const imgs = Array.from(document.images || []);
      await Promise.all(
        imgs.map((img) =>
          img.complete
            ? Promise.resolve()
            : new Promise((res) => {
                img.addEventListener('load', res, { once: true });
                img.addEventListener('error', res, { once: true });
              })
        )
      );
    });
  } catch (e) {
    if (config.debug) console.error('waitForStable warning:', e.message);
  }
}

async function screenshot(page, outDir, device) {
  const file = path.join(outDir, `shot-${device}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

function indexBySection(sections) {
  const out = {};
  for (const s of sections) {
    out[s.index] = { bbox: s.bbox, styles: s.styles };
  }
  return out;
}

function stripMarkers(section) {
  return {
    ...section,
    html: (section.html || '').replace(/\sdata-h2e-section="\d+"/g, ''),
  };
}

function sleep(ms) {
  return new Promise((res) => setTimeout(res, ms));
}

function extractAssetsInPage() {
  const stylesheets = [];
  let combinedCss = '';
  for (const sheet of Array.from(document.styleSheets)) {
    try {
      const rules = sheet.cssRules;
      if (rules) {
        for (const rule of Array.from(rules)) combinedCss += rule.cssText + '\n';
      } else if (sheet.href) stylesheets.push(sheet.href);
    } catch (e) {
      if (sheet.href) stylesheets.push(sheet.href);
    }
  }
  let combinedJs = '';
  const scripts = [];
  for (const script of Array.from(document.querySelectorAll('script'))) {
    if (script.src) scripts.push(script.src);
    else if (script.textContent && script.textContent.trim()) combinedJs += script.textContent + '\n';
  }
  return { stylesheets, combinedCss, scripts, combinedJs };
}

function measureTaggedSections() {
  const props = [
    'display', 'backgroundColor', 'color', 'paddingTop', 'paddingBottom',
    'paddingLeft', 'paddingRight', 'marginTop', 'marginBottom',
    'flexDirection', 'justifyContent', 'alignItems', 'textAlign', 'fontSize', 'gap',
  ];
  const out = {};
  document.querySelectorAll('[data-h2e-section]').forEach((el) => {
    const idx = parseInt(el.getAttribute('data-h2e-section'), 10);
    const rect = el.getBoundingClientRect();
    const cs = window.getComputedStyle(el);
    const styles = {};
    props.forEach((p) => { styles[p] = cs[p]; });
    out[idx] = {
      bbox: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
      styles,
    };
  });
  return out;
}

/**
 * Tag section elements by visual node id for responsive re-measurement.
 *
 * @param {Array<string>} graphIds Visual node IDs.
 */
function tagSectionsFromGraph(graphIds) {
  graphIds.forEach((id, index) => {
    const el = document.querySelector('[data-h2e-vid="' + id + '"]');
    if (el) {
      el.setAttribute('data-h2e-section', String(index));
    }
  });
}

module.exports = { renderToLayout, DEFAULT_CONFIG, RESPONSIVE_WIDTHS };
