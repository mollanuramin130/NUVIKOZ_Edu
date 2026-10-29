# Academic Setup — visual direction for owner review (not applied)

Existing auth styling uses system-ui, navy text #172b45, blue action #164c8c, pale background #f3f6fb, white cards and amber keyboard focus. Preserve that established direction pending owner approval; no platform-wide restyle in the backend slice.

Proposed specification: system font (no external font request), 14/16px body and 24/32px page titles, 4/8/12/16/24/32 spacing, 8px controls/16px cards, 44px minimum touch controls, visible keyboard focus, explicit labels and semantic live feedback. Extract approved colors into semantic CSS variables only after approval. Dark theme requires separately checked contrast, not guessed inversion.

Academic workspace: stable school/session context header; compact left navigation on desktop and accessible collapsed navigation on mobile; Sessions, Classes & sections, Subjects tabs; one primary contextual action. Show real record counts only when authorized API returns them. Dense readable lists, date spans, Future status chips, clear read-only expiry banners. No fabricated dashboards or students. Empty state explains the next eligible setup action; loading skeletons avoid layout jumps; validation maps to fields; 409 conflicts require refresh/review rather than silent replay.

Workflow specification: session name and local date range → server validation → future calendar entry; class name, optional grade label and ordering → catalog entry; later class offerings select the authorized session/class, with optional teacher. Role-aware navigation does not grant permission. CSRF/auth expiry recover through the existing browser client. No storage of tokens, personal data or pending commands in localStorage.

Acceptance preview to prepare before broad UI implementation: desktop academic workspace, narrow-screen form/list, empty/read-only/error states using synthetic data. Owner approval is required for brand-wide visual choices; minor application of approved patterns will not need repeated approval. This specification is a proposal, not an approved global design system or implemented page.
