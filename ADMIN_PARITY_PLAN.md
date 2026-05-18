# Admin Module Parity Plan (AWG -> NK Depth)

## Goal
Make AWG admin modules match the depth/UX of the Namaste Kalyan reference implementation in `D:/GITHUB Projects/Namaste Kalyan/namastekalyan`.

## Reference Baseline
- Portal shell: `public/admin/admin-portal.html`
- Module loader: `public/js/admin-modules/base.js`
- High-depth modules to prioritize:
  - `public/js/admin-modules/menu-editor.js`
  - `public/js/admin-modules/menu-category-designer.js`
  - `public/js/admin-modules/landing-routing.js`
  - `public/js/admin-modules/event-management.js`
  - `public/js/admin-modules/event-guests.js`
  - `public/js/admin-modules/event-entry-scanner.js`
  - `public/js/admin-modules/cashier.js`
  - `public/js/admin-modules/cash-approvals.js`
  - `public/js/admin-modules/verification.js`
  - `public/js/admin-modules/crm-panel.js`
  - `public/js/admin-modules/crm-leads.js`
  - `public/js/admin-modules/whatsapp-cloud.js`
  - `public/js/admin-modules/user-management.js`
  - `public/js/admin-modules/settings.js`

## Current Gap Summary
- AWG uses simplified ES-module cards for several modules where NK has richer workflows.
- Menu Editor / Category Designer in AWG are functional but not NK-level sheet/designer UX.
- Routing & QR Center in AWG is functional but lacks NK-level registry UX, preview, and operational controls.
- Some NK actions do not exist in AWG backend route map; parity must use action aliases/adapters where possible.

## Implementation Strategy
1. UX-first parity for 3 critical modules (Menu Editor, Menu Category Designer, Routing & QR Center).
2. Action compatibility layer: reuse existing AWG actions and alias where needed.
3. Module-by-module parity pass for remaining modules (events, growth, operations, system).
4. End-to-end live smoke validation for all modules.

## Execution Phases
1. Phase A (now):
   - Upgrade `asianwokandgrill.in/js/admin-modules/landing-routing.js` to NK-style control center UX.
   - Upgrade `asianwokandgrill.in/js/admin-modules/menu-category-designer.js` to advanced reorder/designer UX.
   - Upgrade `asianwokandgrill.in/js/admin-modules/menu-editor.js` to richer filtering/preview UX and clearer load lifecycle.
2. Phase B:
   - Bring Event Management/Guests/Scanner forms and list interactions to NK depth.
   - Split CRM panel and CRM leads behaviors to NK-level diagnostics and exports.
3. Phase C:
   - Match settings/user/cashier/coupon/whatsapp screens to NK depth.
   - Run full combined live smoke test and finalize.

## Acceptance Criteria
- Every module in AWG opens and renders without errors.
- Menu Editor + Designer + Routing center match NK depth in controls and workflows.
- No module appears as a placeholder or reduced shell.
- Combined smoke pass succeeds in live admin panel.
