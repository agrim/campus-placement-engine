# Browser performance and visual scope audit

Source baseline: `ff45638`. GoogleChrome/modern-web-guidance foundation
`performance`, `fluid-scaling`, `visual-effects`, `visually-stable-mixed-fonts`,
and `interactions-in-complex-layouts` read in full. Partial assessment only.

## Performance clauses by surface

- Application: server-rendered HTML, deferred same-origin app.js (5,226 bytes),
  ordinary blocking app.css (11,115 bytes). No critical-CSS extraction/media split.
  No fetched fonts or third-party scripts. No image hero loader or client router.
- Marketing: Vite module script/build minification, local assets. Current built
  assets are about237KB JS/36KB CSS uncompressed. Source HTML starts with an empty
  React root: above-fold text depends on JS. No SSR/prerendered marketing HTML or
  route/demo code split. Do not call the build size a measured slow-load failure;
  cold-network/mobile CPU traces and an explicit budget are still required.
- INP: app refresh timer performs bounded text updates each second and skips while
  hidden/paused, reloading only on expiry; not a heavy polling computation.
  Login error handlers are bounded. Marketing reducer interactions have synthetic
  data; no measured large-data INP or main-thread attribution. No basis for
  arbitrary scheduler/worker migration without a traced long task.
- Containment: native board POST/reload behavior is not a drag-and-drop board.
  Marketing demo changes small React subtrees. The complex-layout guide remains
  partial: no dense production trace or safe containment boundary/placeholder
  validation. Blanket content-visibility could clip shadows and affect focus.
- Media: ProductTour declares1440x900 video and390x844 image, lazy attributes,
  native controls/captions/poster. Video is WebM-only, preload metadata rather than
  none; lazy video support is progressive, not universal. Responsive screenshot
  variants and modern poster formats are not implemented. LCP/CLS remain unmeasured.
- Caching: no browser service-worker strategy. Deployment asset cache/compression
  and authenticated-document handling must be tested in intended hosting, not
  inferred from source. Do not add offline caching of private candidate data.
- Font and third-party resource-hint recipes: no authored webfont downloads or
  third-party blocking scripts. Do not preload every system-font family name.

## Fluid scaling

Marketing font clamps use rem minimum/maximum bounds with ratios below2.5,
for example4.2→7.8rem and2.8→5.3rem. Application uses relative type and native
flex/grid. No container units/containment contexts: container fallback clauses
are not active. Components remain viewport-coupled. Prior root-font reflow tests
are not actual browser zoom/OS text scaling, which remain unverified.

## Visual effects and mixed fonts

Both authored stylesheets use rectangular solid shadows, not gradients,
color-mix, blend textures or gradient charts. Drop shadows for transparent
non-rectangular art and semantic tables for gradient charts are not applicable.
Layered realistic soft shadows would change the intentional editorial style;
absence alone is not a demonstrated functional defect.

Mixed-font normalization is a real uncovered guide requirement: marketing
wordmarks/headings use a serif family with nested sans mark/controls; app textareas
use monospace against the system body font. No font-size-adjust/from-font or
measured fallback metrics are supplied. There is no downloaded font/theme switch,
so those branches are not active. A global metric change without visual/reflow
validation could alter branded layouts. Keep this partial/open rather than
claiming compliance or adding arbitrary aspect ratios.
