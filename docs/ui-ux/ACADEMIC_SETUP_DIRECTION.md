# Academic Setup — approved Executive Enterprise + Modern EdTech Premium Hybrid

Owner explicitly approved Option A + Option B Hybrid for Slice C on2026-10-30 (local owner date). This authorizes the implemented academic workspace direction, not unsupported domain features, production deployment or blanket acceptance of unexecuted UI tests.

## Design tokens and architecture

Scoped `apps/edu-web/src/app/academic/academic.css` extends the existing system-font baseline; no external fonts, imagery, icon package or runtime asset service. Navy#10283f sidebar, ink#172b45, blue#164c8c, reserved teal#087b79, pale#f3f6fb background and white surfaces. Muted text#53667c, semantic success/error/teal surfaces, 8px controls/16px panels,4/8/12/16/24/32 spacing,44px touch controls, understated shadows. Amber focus darkened to#a76900 for visible contrast; no auth stylesheet overwritten. Control border#71869b. Measured contrast on white: ink14.31:1, muted5.90:1, primary white/blue8.58:1, amber4.50:1, control border3.76:1; teal text on pale teal6.26:1. These measured token pairs do not imply a full WCAG certification.

Reusable AcademicWorkspace shell with sidebar/topbar, skip link, current authorized school reference (no invented school name), responsive in-flow mobile menu with focus/Escape behavior. Desktop breakpoint1200, mobile760; isolated `.ac-main` overrides narrow global auth main only within academic workspace. No dark-mode toggle claimed; a dark navigation surface is not a full dark theme.

Client components are separated into workspace guard/context, typed API/client hooks, shared controls and Sessions/Classes/Sections/Subjects screens. Inline forms avoid unsupported modal/dialog focus behavior. Shared PageHeading, Panel, Field, Feedback, SelectRecord, RecordList and Submit provide semantic landmarks, actual associated labels, field error references, status/alert feedback, pagination, visible focus and loading/empty states. Search is explicitly current loaded page only; no fabricated record totals or charts.

## Implemented screen scope

`/academic` overview: connected workflow navigation; no invented analytics. `/academic/sessions`: actual list/create future calendars, ISO dates checked by server in school timezone. No client date-min assumption, activate/edit/close/delete actions. `/academic/classes`: class catalog + explicit session offerings. `/academic/sections`: session/offering context, scoped list/create, teacher assignment explicitly unavailable. `/academic/subjects`: catalog + explicit compulsory/optional offering assignments with JSON booleans. Native Flutter UI is not included.

Backend remains the authority. Session/identity/role/MFA/context changes remove the private subtree and in-memory selections, forms and intent keys; canceled/late requests cannot restore it. No localStorage command/token cache. Browser failures distinguish current MFA, session expiry, CSRF419, field422, conflict409 and rate429. Generic server403 is explained as unavailable eligibility/configuration, not a fabricated entitlement status. Authorized reads may remain available. No state-changing automatic retry.

## Verification status

At implementationfdd7bee706ab724ace4bba02e2b257e945db2889, all28 real HTTPS journeys (8 new actual UI cases +20 regressions) passed;7 isolated client tests, local and hosted lint/typecheck/build passed. Full actual UI login→MFA→session→class→offering→section→subject→compulsory/optional assignment completed. Desktop1440 and mobile390 screenshots were captured from actual synthetic pages, checksum-verified and visually inspected. Tablet900/mobile390 page overflow checks passed; associated labels,44px primary touch target, visible keyboard outline and Tab navigation passed. Screenshot artifact and exact checksums are in M2_SLICE_C_RESULTS.md. The screenshots are not the earlier AI concepts. Formal WCAG/screen-reader audit and owner screen acceptance remain pending; no dark-theme or Flutter UI completion claimed.

Route navigation tests wait for the destination heading before using shared selector names; they do not mistake an old screen for a committed destination. Alerts are scoped to the relevant form because Next.js also has a route-announcer alert. All assertions retained; no security mechanisms changed for test success. No dialogs were introduced, so no modal-focus-trap claim. Mobile navigation focuses its links on expansion and supports Escape. A subsequent copy/evidence commit requires fresh exact-head CI.
