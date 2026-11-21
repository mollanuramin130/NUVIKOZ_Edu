# Unified Next.js foundation

Six role route shells;no protected records or login UI wired yet. Run npm ci, npm run typecheck and npm run build. API client implements public health only;cookie/CSRF protected integration awaits next increment. Never store tokens in localStorage or pass private data through build-time props.

## M2 Academic Workspace

Owner-approved Executive Enterprise + Modern EdTech Premium Hybrid lives under `/academic` (School Admin + current MFA). Sessions, classes/offerings, sections and subjects use the actual browser-only academic API. No teacher assignment/session activation/edit/delete/mobile endpoint implied. Scoped CSS preserves existing auth screens; reusable components in `src/components/academic` and typed API in `src/lib/academic-api.ts`. `npm run test:client` runs7 isolated boundary tests; it is not real API/browser evidence. Existing secured HTTPS CI runs actual UI journeys and uploads only synthetic screenshots plus checksums; no cookies, HAR, traces or MFA secrets in artifacts. Read canonical handoff for the current tested SHA and pending gates.
