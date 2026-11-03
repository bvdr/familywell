// Fetches each brand's logo for resources/shop-brands.json into public/brands/<slug>.png (Google's 128 px favicon,
// which beat reading the shops' own sites in our tests: no bot walls). Run by hand when brands are added, look at
// the result, commit the PNGs. The app itself never calls Google: logos ship with it.
//   node scripts/fetch-brand-logos.mjs            # only missing ones
//   node scripts/fetch-brand-logos.mjs --all      # refetch everything
import { Buffer } from 'node:buffer';
import fs from 'node:fs';
import process from 'node:process';
const brands = JSON.parse(fs.readFileSync('resources/shop-brands.json', 'utf8'));
const all = process.argv.includes('--all');
for (const b of brands) {
    const file = `public/brands/${b.slug}.png`;
    if (!all && fs.existsSync(file)) continue;
    const r = await fetch(`https://www.google.com/s2/favicons?domain=${b.domain}&sz=128`);
    const buf = Buffer.from(await r.arrayBuffer());
    fs.writeFileSync(file, buf);
    console.log(b.slug.padEnd(18), r.status, buf.length);
}
