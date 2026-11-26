# FE Accessibility Acceptance

Status: `WCAG 2.2 AA RELEASE GATE`

All storefront, customer, admin, fulfillment, reporting, and platform screens
must meet WCAG 2.2 AA for keyboard, screen reader, zoom, touch, and reduced
motion use. Accessibility is a release property, not a best-effort audit.

## Measurable gates

- Automated axe scan: 0 critical or serious findings on every release-critical
  route; all remaining findings have an owner and approved time-boxed exception.
- Keyboard: 100% of interactive controls reachable in logical order; 0 keyboard
  traps; visible focus on every focusable element; skip link reaches main content.
- Reflow: no loss of content or two-dimensional scrolling at 320 CSS px width,
  400% zoom, and text spacing overrides.
- Contrast: normal text at least 4.5:1, large text at least 3:1, and UI/focus
  indicators at least 3:1 against adjacent colors.
- Target size: actionable controls meet WCAG 2.2 AA minimum 24 by 24 CSS px,
  or have compliant spacing exceptions.
- Forms: every input has a programmatic label, errors identify the field and
  correction, and submitted values are preserved after recoverable errors.
- Async UX: loading, empty, validation, 401, 403, 404, 409, 422, 429, and 500
  states are announced or otherwise available without relying on color.
- Screen reader smoke: zero unlabeled icon-only actions, duplicate landmarks,
  or inaccessible dialogs in the critical journeys.

## Test matrix

| Surface | Keyboard | Screen reader | Zoom/reflow | Mobile touch |
|---|---|---|---|---|
| Storefront/catalog/product | Chromium + NVDA | NVDA/Firefox | 320 px and 400% | iOS Safari, Android Chrome |
| Cart/checkout/order result | Chromium + VoiceOver | VoiceOver/Safari | 320 px and 400% | iOS Safari, Android Chrome |
| Account/privacy | Chromium + NVDA | NVDA/Firefox | 320 px and 400% | iOS Safari |
| Admin/order/inventory | Chromium + NVDA | NVDA/Firefox | 320 px and 400% | Android Chrome |
| Reports/export/health | Chromium + NVDA | NVDA/Firefox | 320 px and 400% | Android Chrome |

## Responsive and browser support matrix

| Class | Viewports | Browser/version policy | Required coverage |
|---|---|---|---|
| Small mobile | 320 x 800, 375 x 812 | latest two iOS Safari and Android Chrome major versions | storefront, cart, checkout, account |
| Large mobile | 390 x 844, 412 x 915 | latest two iOS Safari and Android Chrome major versions | storefront and checkout smoke |
| Tablet | 768 x 1024, 1024 x 1366 | latest two Safari and Chromium major versions | storefront and admin smoke |
| Desktop | 1280 x 800, 1440 x 900 | latest two Chrome/Edge and latest Firefox | all release-critical screens |
| Reduced/legacy | 320 CSS px, 400% zoom, reduced motion | supported browsers with OS text scaling enabled | critical journey and accessibility checks |

Support means no functional or content loss, not pixel-identical rendering.
Browser upgrades are re-baselined monthly; a newly released stable browser is
added within 30 days. Unsupported browsers receive a translated, accessible
upgrade message before authenticated or financial workflows begin.

Run automated scans on route load and after opening menus, dialogs, drawers,
validation errors, pagination, and async result panels. Manual tests are
required for focus order, announcements, semantics, and error recovery.

## Acceptance scenarios

1. Tab from the address bar through navigation, main content, dialogs, and
   footer; focus order follows the visual task order and never leaves a modal.
2. Open and close a menu, combobox, date picker, and destructive confirmation
   using keyboard only; return focus to the invoking control.
3. Submit checkout with invalid fields; each error is announced, associated with
   its input, and the first invalid field can be reached without scrolling loss.
4. Place an order with a duplicate idempotency key; the recoverable conflict is
   conveyed in text and the retry action has an accessible name.
5. Change locale and currency; accessible names, numbers, dates, and live
   regions update without exposing implementation keys.
6. Use the admin role matrix; hidden capabilities are absent from the tree and
   direct unauthorized routes render an accessible 401/403 state.
7. Enable reduced motion and high zoom; no essential status, progress, or
   destructive warning is conveyed only through animation or color.

## Evidence required

Store axe HTML/JSON, browser/version, viewport, zoom, assistive technology,
route, commit, test id, and screenshots/video for failures. A manual checklist
must name the screen and exact interaction. Map each result to a route's
operation ids where data is involved; a screenshot without route and operation
context is not sufficient evidence.

## Exceptions

An exception requires criterion, affected route/screen, severity, user impact,
owner, mitigation, expiry date, and product/security approval. No exception is
allowed for keyboard traps, inaccessible authentication, inaccessible checkout,
or inability to identify a financial amount or destructive action.
