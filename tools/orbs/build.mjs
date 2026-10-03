// npm install && npm run build  ->  ../../server/app/vendor/orbs.js
import * as esbuild from 'esbuild';
import { readFile } from 'node:fs/promises';

// The orb draws at size x devicePixelRatio (max 2). The app shows it larger
// than the 64px preset, so a canvas may carry data-dpr with the resolution to
// draw at; the geometry stays the tuned 64px design, only sharper.
const sharpOrb = {
  name: 'sharp-orb',
  setup(b) {
    b.onLoad({ filter: /thinking-orbs[\\/]dist[\\/]index\.es\.js$/ }, async (a) => {
      const src = await readFile(a.path, 'utf8');
      const from = 'const dpr = Math.min(2, typeof devicePixelRatio !== "undefined" && devicePixelRatio || 1);';
      if (!src.includes(from)) throw new Error('thinking-orbs changed: update the data-dpr patch');
      return { contents: src.replace(from, 'const dpr = +canvas.dataset.dpr || Math.min(2, typeof devicePixelRatio !== "undefined" && devicePixelRatio || 1);'), loader: 'js' };
    });
  },
};

await esbuild.build({
  entryPoints: ['entry.jsx'], bundle: true, minify: true, format: 'iife', target: ['safari15', 'chrome100'],
  define: { 'process.env.NODE_ENV': '"production"' }, legalComments: 'eof',
  outfile: '../../server/app/vendor/orbs.js', plugins: [sharpOrb],
});
console.log('built server/app/vendor/orbs.js');
