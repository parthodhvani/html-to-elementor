#!/usr/bin/env node
'use strict';

/**
 * Visual similarity validation (Phase: fidelity scoring).
 *
 * Compares two screenshots (e.g. the original HTML page vs. the generated
 * Elementor page) and prints a similarity score. Both images are normalised to
 * a common size in a headless-Chromium canvas, then compared with a
 * luminance-based RMSE over a downscaled grid (robust to minor sub-pixel and
 * anti-aliasing differences).
 *
 * Usage:
 *   node compare.js original.png generated.png
 */

const fs = require('fs');
const puppeteer = require('puppeteer');

async function main() {
  const [a, b] = process.argv.slice(2);
  if (!a || !b || !fs.existsSync(a) || !fs.existsSync(b)) {
    process.stderr.write('Usage: node compare.js <imageA.png> <imageB.png>\n');
    process.exit(2);
    return;
  }

  const toDataUri = (p) => 'data:image/png;base64,' + fs.readFileSync(p).toString('base64');

  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  try {
    const page = await browser.newPage();
    const result = await page.evaluate(
      async (srcA, srcB) => {
        const W = 160;
        const H = 240;

        function load(src) {
          return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = src;
          });
        }
        function grid(img) {
          const c = document.createElement('canvas');
          c.width = W;
          c.height = H;
          const ctx = c.getContext('2d');
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, W, H);
          ctx.drawImage(img, 0, 0, W, H);
          return ctx.getImageData(0, 0, W, H).data;
        }

        const [ia, ib] = await Promise.all([load(srcA), load(srcB)]);
        const da = grid(ia);
        const db = grid(ib);

        let sumSq = 0;
        let n = 0;
        for (let i = 0; i < da.length; i += 4) {
          const la = 0.299 * da[i] + 0.587 * da[i + 1] + 0.114 * da[i + 2];
          const lb = 0.299 * db[i] + 0.587 * db[i + 1] + 0.114 * db[i + 2];
          const d = la - lb;
          sumSq += d * d;
          n += 1;
        }
        const rmse = Math.sqrt(sumSq / n); // 0..255
        const similarity = Math.max(0, 100 - (rmse / 255) * 100);
        return { rmse: Math.round(rmse * 100) / 100, similarity: Math.round(similarity * 10) / 10 };
      },
      toDataUri(a),
      toDataUri(b)
    );

    process.stdout.write(JSON.stringify(result) + '\n');
    process.exit(0);
  } catch (e) {
    process.stderr.write('Compare failed: ' + e.message + '\n');
    process.exit(1);
  } finally {
    await browser.close();
  }
}

main();
