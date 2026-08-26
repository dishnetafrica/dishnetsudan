// No PIL and no ImageMagick in this container, but Chromium has a full image
// pipeline. Fetch the file, trim the white studio margin so the product fills
// the frame, scale to a sane web width, re-encode as WebP.
// createImageBitmap rather than new Image(): the latter would not decode here.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const jobs = JSON.parse(process.argv[2]);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium',
    args: ['--no-proxy-server'] });          // else localhost goes via the agent proxy
  const p = await b.newPage();
  await p.goto('http://127.0.0.1:8901/');
  for (const job of jobs) {
    const out = await p.evaluate(async ({ url, maxW, quality, trim }) => {
      const res = await fetch(url);
      const bmp = await createImageBitmap(await res.blob());
      const c = document.createElement('canvas');
      c.width = bmp.width; c.height = bmp.height;
      const ctx = c.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(bmp, 0, 0);

      let sx = 0, sy = 0, sw = c.width, sh = c.height;
      if (trim) {
        const d = ctx.getImageData(0, 0, c.width, c.height).data;
        let x0 = c.width, y0 = c.height, x1 = 0, y1 = 0;
        for (let y = 0; y < c.height; y++) {
          for (let x = 0; x < c.width; x++) {
            const i = (y * c.width + x) * 4;
            // anything meaningfully darker than the studio backdrop
            if (d[i + 3] > 8 && (d[i] < 245 || d[i + 1] < 245 || d[i + 2] < 245)) {
              if (x < x0) x0 = x; if (x > x1) x1 = x;
              if (y < y0) y0 = y; if (y > y1) y1 = y;
            }
          }
        }
        if (x1 > x0 && y1 > y0) {
          const padX = Math.round((x1 - x0) * 0.04), padY = Math.round((y1 - y0) * 0.07);
          sx = Math.max(0, x0 - padX); sy = Math.max(0, y0 - padY);
          sw = Math.min(c.width - sx, x1 - x0 + padX * 2);
          sh = Math.min(c.height - sy, y1 - y0 + padY * 2);
        }
      }
      const scale = Math.min(1, maxW / sw);
      const o = document.createElement('canvas');
      o.width = Math.round(sw * scale); o.height = Math.round(sh * scale);
      const octx = o.getContext('2d');
      octx.imageSmoothingQuality = 'high';
      // Flatten onto white -- WebP keeps alpha and a transparent card looks broken.
      octx.fillStyle = '#ffffff'; octx.fillRect(0, 0, o.width, o.height);
      octx.drawImage(c, sx, sy, sw, sh, 0, 0, o.width, o.height);
      return { data: o.toDataURL('image/webp', quality), w: o.width, h: o.height,
               trimmed: `${sw}x${sh} of ${c.width}x${c.height}` };
    }, job);
    fs.writeFileSync(job.out, Buffer.from(out.data.split(',')[1], 'base64'));
    const kb = fs.statSync(job.out).size / 1024;
    console.log(`${path.basename(job.out)}  ${out.w}x${out.h}  ${kb.toFixed(0)} KB   (kept ${out.trimmed})`);
  }
  await b.close();
})();
