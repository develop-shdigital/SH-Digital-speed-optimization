async () => {
  const lcp = await new Promise((resolve) => {
    const list = [];
    new PerformanceObserver((l) => list.push(...l.getEntries())).observe({ type: "largest-contentful-paint", buffered: true });
    setTimeout(() => resolve(list), 800);
  });
  const describe = (el) => el && ({ tag: el.tagName, id: el.id || undefined, cls: (el.className && el.className.toString().slice(0, 160)) || undefined,
    attrs: [...el.attributes].filter(a => /^data-|^style$|loading|fetchpriority|decoding/.test(a.name)).map(a => `${a.name}=${a.value.slice(0, 80)}`).join(" ") || undefined });
  const chain = (el) => { const out = []; for (let n = el; n && n !== document.body && out.length < 14; n = n.parentElement) {
      const cs = getComputedStyle(n); const d = describe(n);
      if (cs.animationName !== "none" || (cs.transitionDuration !== "0s" && /opacity|transform|all/.test(cs.transitionProperty)) || /anim|aos|fade|reveal|kb-|kt-|wow|invisible|motion|gsap|lottie/i.test((d.cls || "") + (d.attrs || "")))
        out.push({ ...d, animation: `${cs.animationName} ${cs.animationDuration} delay ${cs.animationDelay}`, transition: `${cs.transitionProperty} ${cs.transitionDuration} delay ${cs.transitionDelay}`.slice(0, 120), opacity: cs.opacity });
    } return out; };
  const entries = lcp.map((e) => ({ startTime: Math.round(e.startTime), renderTime: Math.round(e.renderTime), loadTime: Math.round(e.loadTime), size: e.size, url: e.url || undefined,
    element: describe(e.element), text: e.element?.textContent?.trim().replace(/\s+/g, " ").slice(0, 140), outer: e.element?.outerHTML.slice(0, 500), animatedAncestors: e.element ? chain(e.element) : [] }));
  const anims = document.getAnimations().map(a => ({ name: a.animationName || a.constructor.name, target: describe(a.effect?.target)?.cls?.slice(0, 80) || a.effect?.target?.tagName, delay: a.effect?.getTiming().delay, duration: a.effect?.getTiming().duration, state: a.playState })).slice(0, 25);
  const loaders = [...document.querySelectorAll('[class*="preload"],[class*="loader"],[id*="preload"],[id*="loader"]')].slice(0, 8).map(describe);
  const nav = performance.getEntriesByType("navigation")[0];
  const paints = Object.fromEntries(performance.getEntriesByType("paint").map(p => [p.name, Math.round(p.startTime)]));
  return { lcp: entries, paints, domContentLoaded: Math.round(nav.domContentLoadedEventEnd), load: Math.round(nav.loadEventEnd),
    domElements: document.getElementsByTagName("*").length, runningAnimations: anims, loaderLikeElements: loaders };
}
