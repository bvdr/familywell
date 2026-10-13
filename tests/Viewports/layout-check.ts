import { expect, type Page } from '@playwright/test';

// Runs in the page. Returns every layout problem found; an empty list means the page is clean.
//  1. The page itself never scrolls sideways (document wider than the viewport).
//  2. No box scrolls sideways or hides cut-off content (a table squeezed into an overflow-x:auto or
//     overflow:hidden box still counts as broken).
//  3. No two pieces of visible text overlap (e.g. "VERIFIEDFAMILIES" headers running into each other).
//  4. Table headers stay on one line; no visible text pokes past the right edge of the viewport.
// Text is measured with Range rects clipped by every overflow-hiding ancestor, so an intentional ellipsis
// (truncate) is judged by what is actually shown, not by the full hidden string.
function findLayoutProblems(): string[] {
  const problems: string[] = [];
  const vw = window.innerWidth;
  const name = (el: Element) => {
    const text = (el.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 40);
    return `<${el.tagName.toLowerCase()}${el.getAttribute('data-slot') ? ` data-slot=${el.getAttribute('data-slot')}` : ''}> "${text}"`;
  };

  window.scrollTo(0, 0);
  for (const el of document.querySelectorAll('[data-scroll-x]')) el.scrollTo(0, 0);

  const docWidth = document.documentElement.scrollWidth;
  if (docWidth > vw) problems.push(`page scrolls sideways: content is ${docWidth}px wide in a ${vw}px viewport`);

  for (const el of document.querySelectorAll('body *')) {
    const ox = getComputedStyle(el).overflowX;
    // A deliberate sideways scroller opts out with data-scroll-x: always, or with data-scroll-x="mobile"
    // only below md (the DataTable: it may scroll on a phone, it must fit on a desktop).
    const optOut = el.getAttribute('data-scroll-x');
    const allowed = optOut !== null && (optOut !== 'mobile' || vw < 768);
    if ((ox === 'auto' || ox === 'scroll') && el.scrollWidth > el.clientWidth + 1 && !allowed) {
      problems.push(`box scrolls sideways (${el.scrollWidth}px content in ${el.clientWidth}px): ${name(el)}`);
    }
    // overflow:hidden that actually hides something is the same bug, just invisible to scrollWidth checks
    // (the old admin hid an off-screen table this way). An intentional ellipsis and sr-only (1px) are fine.
    // Form controls scroll their own value by design, so they are skipped.
    if ((ox === 'hidden' || ox === 'clip') && el.clientWidth > 1 && el.scrollWidth > el.clientWidth + 1
        && getComputedStyle(el).textOverflow !== 'ellipsis' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) {
      problems.push(`content cut off (${el.scrollWidth}px content in ${el.clientWidth}px, overflow hidden): ${name(el)}`);
    }
  }

  type R = { left: number; right: number; top: number; bottom: number };
  const clip = (r: R, el: Element | null): R | null => {
    for (let a = el; a && a !== document.documentElement; a = a.parentElement) {
      const cs = getComputedStyle(a);
      if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') {
        const b = a.getBoundingClientRect();
        r = { left: Math.max(r.left, b.left), right: Math.min(r.right, b.right), top: Math.max(r.top, b.top), bottom: Math.min(r.bottom, b.bottom) };
      }
    }
    return r.right - r.left > 1 && r.bottom - r.top > 1 ? r : null;
  };

  // Content scrolling under a fixed bar is by design, so text is only compared with text in the same layer:
  // the nearest fixed ancestor, or the normal flow. Sticky is NOT a layer: it is checked at scroll 0 (below),
  // where sticky things sit in their normal place, and sticky header cells must still not overlap each other.
  const layerOf = (el: Element): Element | null => {
    for (let a: Element | null = el; a; a = a.parentElement) {
      if (getComputedStyle(a).position === 'fixed') return a;
    }
    return null;
  };

  const texts: { el: Element; r: R; layer: Element | null }[] = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const el = n.parentElement;
    if (!el || !n.textContent?.trim()) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || Number(cs.opacity) === 0) continue;
    const range = document.createRange();
    range.selectNodeContents(n);
    for (const raw of range.getClientRects()) {
      const r = clip(raw, el);
      if (r) texts.push({ el, r, layer: layerOf(el) });
    }
  }

  for (const t of texts) {
    if (t.r.right > vw + 1) problems.push(`text runs past the right edge (${Math.round(t.r.right)}px > ${vw}px): ${name(t.el)}`);
  }

  const seen = new Set<string>();
  for (let i = 0; i < texts.length; i++) {
    for (let j = i + 1; j < texts.length; j++) {
      const a = texts[i], b = texts[j];
      if (a.layer !== b.layer || a.el === b.el || a.el.contains(b.el) || b.el.contains(a.el)) continue;
      const w = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
      const h = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
      if (w > 2 && h > 2) {
        const msg = `text overlaps: ${name(a.el)} and ${name(b.el)}`;
        if (!seen.has(msg)) { seen.add(msg); problems.push(msg); }
      }
    }
  }

  for (const th of document.querySelectorAll('th')) {
    const range = document.createRange();
    range.selectNodeContents(th);
    const shown = [...range.getClientRects()].map((r) => clip(r, th)).filter((r): r is R => r !== null);
    const lines = new Set(shown.map((r) => Math.round(r.top)));
    if (lines.size > 1) problems.push(`table header wraps onto ${lines.size} lines: ${name(th)}`);
  }

  return problems;
}

// Wait until the page has settled (lazy route loaded, no skeletons).
export async function settle(page: Page) {
  await page.waitForLoadState('networkidle');
  await expect(page.locator('[data-slot="skeleton"], [aria-busy="true"]')).toHaveCount(0);
  await expect(page.getByText('Loading…')).toHaveCount(0);
  await page.evaluate(() => document.fonts.ready);
}

// Settle, run `before` (take the screenshots, so a failing page still leaves them behind), then assert it is clean.
export async function expectCleanLayout(page: Page, before?: () => Promise<unknown>) {
  await settle(page);
  await before?.();
  expect(await page.evaluate(findLayoutProblems)).toEqual([]);
}
