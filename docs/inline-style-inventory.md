# Inline presentation inventory

Baseline 81ab5ad; full upstream `css` foundation reviewed (all 11 sections).

The PHP application and module view inventory originally had 16 style attributes:
- two data-dependent board styles (status heading colors, card status border);
- six trivial checkbox width:auto declarations in admin/records;
- two static panel width limits in login/student;
- six static board note declarations (accommodation 1, urgent 2, attention 3).

The 14 static declarations are now stylesheet classes with the same values.
The six width:auto declarations were allowed trivial inline examples, but moving
these repeated rules avoids duplication. Static note colors and panel dimensions
were the material foundation clause gap. Semantic classes retain text labels, so
status is not conveyed by color alone. The existing danger token is reused.

Exactly two style attributes remain in the PHP view inventory: board heading
--status-bg/--status-text runtime data and the data-dependent status border. The
latter is deliberately not converted to a custom-property shorthand: malformed
legacy colors can have different invalid-at-computed-value fallback behavior.
Dynamic configured colors are not a reason to remove styling or weaken CSP.
No CSP, workflow data, status validation or authentication semantics changed.

A Chromium comparison checks six before/after element cases at 320/1280px,
100/200% root font and normal/forced colors (48 comparisons). Width/height/color,
font weight, border and max-width match. This is targeted visual/computed evidence,
not all-browser rendering equivalence or whole-guide compliance. The app does
not yet use cascade layers globally; physical properties and intentional light
scheme remain, and marketing's layered universal box-sizing reset is separate.
