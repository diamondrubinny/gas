=== Gas Safety Manager ===
Contributors: diamondrubinny
Tags: safety, equipment, inspections, hebrew, rtl
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 2.13.6
License: Proprietary — Technion Safety Unit

Gas systems maintenance management for the Technion Safety Unit. Manages equipment, inspections, faults, and QR codes with native WordPress storage.

== Description ==

Gas Safety Manager replaces the Monday.com backend of the Technion Safety Unit's gas maintenance system with native WordPress storage.

Features:
* Equipment registry (25 fields), inspections with per-item checklists, faults, QR scan log.
* Full REST API (/gas/v1) with capability-based authorization.
* Three roles: administrator, lab manager (gsm_lab_manager), PI (gsm_pi).
* Quarterly-deadline dashboard — only full quarterly inspections reset the timer; open defects counted directly from checklist items.
* Public bridge page via unguessable QR token, with legacy Monday-ID fallback so existing printed labels keep working.
* One-time Monday migration via the existing portal (no Monday secret stored in WordPress).
* Privacy-aware scan log (hashed IP only).

Pairs with the companion "Gas Safety" theme for the portal look (navy/gold, RTL, Rubik).

== Installation ==

1. Upload and activate the plugin. Tables are created automatically.
2. (Optional) Activate the companion Gas Safety theme.
3. Create a page, add the [gas_app] shortcode, and choose the "Gas App" template.
4. To import from Monday: open "Gas Migration", generate a key, run the console script in the portal, then revoke the key.

== Changelog ==

= 2.13.6 =
* Accessibility (WCAG 2.1 AA / IS 5568): automated axe-core scan of 35 views went from 344 failing elements to 0, plus 22 keyboard / focus checks. See docs/accessibility.md.
* Every form field has a programmatically linked label; unnamed selects/checkboxes got explicit names; the QR image has alt text.
* Color contrast fixed in the design tokens (muted text, gold text, warning pills, the Excel button) — brand navy/gold unchanged.
* Dialogs (equipment / fault / inspection forms, QR-page fault report): role="dialog", focus moves in and is trapped, Escape closes, focus returns to the opener.
* Attachment upload is reachable with the keyboard (the file input was display:none).
* Screen changes update the page title and move focus to the new heading; "skip to main content" link; labelled main navigation.
* Status and error messages are live regions (announced by screen readers).
* App and QR pages always declare lang="he-IL" (screen readers use a Hebrew voice), clear focus ring, prefers-reduced-motion support.
* No DB change — stays v8.

= 2.13.5 =
* Privacy: the Rubik font is now bundled with the plugin (public/assets/fonts, SIL OFL 1.1) instead of loading from Google Fonts — visitors' IP addresses are no longer sent to Google, and the font now also works on networks that block external CDNs. Applies to the app, the QR bridge page, the sticker print sheet and the public dashboard.
* Privacy: WordPress core's emoji-to-image replacement is turned off on the app and bridge pages only (it loaded emoji images from s.w.org, a third-party host). Browsers render emoji natively. Other site pages are unchanged.
* CSP tightened accordingly: no Google font hosts, no blob: workers.
* No DB change — stays v8.

= 2.13.4 =
* Hardening from a WordPress security-sniff (PHPCS) pass — 0 violations remain:
* create-pages.php can no longer be executed by a direct HTTP request (it used to fatal and could reveal the server path when display_errors is on).
* Rate limiter sanitizes REMOTE_ADDR before validating it.
* Attachment and temp-file deletion use wp_delete_file() instead of unlink().
* Remaining interpolated SQL documented (table names / whitelisted ORDER BY only; all user values are bound through prepare()).
* Dev: CI (GitHub Actions) — PHP 8.1–8.4 lint, PHPCS security + PHP 8.1 compatibility (blocking), unit tests, and end-to-end tests against WordPress 6.4.5 and latest.
* No DB change — stays v8.

= 2.13.3 =
* Content-Security-Policy on the app ([gas_app]) and QR bridge ([gas_bridge]) pages: script-src 'self' + a per-request nonce — no 'unsafe-inline', no 'unsafe-eval'. Even if an XSS bug ever slipped in, injected <script> tags and on*="" handlers would not run. WordPress's own scripts (including core inline ones) get the nonce automatically via wp_script_attributes / wp_inline_script_attributes. Other pages of the site are not affected.
* CSP mode switch for rollout / troubleshooting: define( 'GSM_CSP_MODE', 'report-only' ) (or 'off') in wp-config.php, or the gsm_csp_mode filter. Directives are filterable via gsm_csp_directives.
* Refactor: all inline onclick="" handlers (about 25) replaced by data-gsm-nav / data-gsm-action attributes and one delegated listener. Print windows (QR + sticker sheet) no longer contain inline scripts; the app triggers printing itself.
* Public status dashboard: script moved to an external file + a meta CSP (default-src 'none').
* Fix: Excel exports crashed on sites where nothing else loads wp-admin/includes/file.php ("Call to undefined function wp_tempnam()").
* Fix: the QR fault form's anti-spam timer used a one-second clock and sometimes rejected real people who submitted 2–3 seconds after opening the form ("שליחה מהירה מדי").
* No DB change — stays v8.

= 2.13.2 =
* Security review (XSS) of all front-end rendering — no exploitable XSS found: every server/user value rendered into HTML goes through esc(). Hardening and adjacent fixes:
* Public status dashboard: the ?api= URL parameter is now restricted to the site's own origin. Before, a crafted link (?api=https://other-site) made the page on the trusted domain fetch and display someone else's numbers (content spoofing) and send the token there.
* CSV formula injection (CWE-1236): cells starting with = + - @ (or tab/CR) are prefixed with an apostrophe in every CSV export (BI CSV, database CSV, Excel CSV fallback). Anonymous fault reports from the QR page could otherwise plant =HYPERLINK/DDE payloads that run when a manager opens the export in Excel. Plain numbers are unchanged. XLSX exports were already safe (inline strings).
* Inline config (window.GSM_CONFIG / GSM_BRIDGE) is JSON-encoded with JSON_HEX_TAG|AMP|APOS|QUOT so no setting value can break out of the script block.
* Router error messages are rendered as text; #/equipment/<id> accepts numeric ids only.
* No DB change — stays v8.

= 2.13.1 =
* Security fix (High): the public status dashboard link no longer exposes the full data export. Until now /bi/public, /bi/data and /bi/ical all accepted the same BI token, so anyone holding the "aggregate-only" dashboard link could download every equipment row, including contact names and emails. The dashboard now uses its own token (gsm_public_token), managed and rotated from "תקשורת והתראות"; /bi/public rejects the BI token and /bi/data + /bi/ical reject the public token. New routes: GET /gas/v1/bi/public-token, POST /gas/v1/bi/public-token/rotate (manager).
* UPGRADE NOTE: previously shared public dashboard links stop working. Copy the new link from "תקשורת והתראות". BI export links (Power BI / Excel / iCal) are unchanged — but if the old dashboard link was shared outside the team, rotate the BI token too ("אינטגרציות" > "החלף טוקן").
* Hardening: per-IP rate limiting on /bi/data and /bi/ical (60 per 5 minutes); removed "Access-Control-Allow-Origin: *" from the exports (Power BI / Excel / calendar clients fetch server-side and do not need it); exports send no-store / noindex / nosniff / no-referrer headers; the public dashboard page sets referrer=no-referrer so the token does not leak via the Referer header; token rotations are written to the audit log; uninstall removes both tokens.
* No DB change — stays v8.

= 2.13.0 =
* Secure attachments (photos / PDFs) on inspections and faults ("📎 צרופות"): upload, preview thumbnails, download, and delete from the edit forms. Security controls — allow-list by BOTH extension and real MIME (wp_check_filetype_and_ext + finfo + getimagesize for images), 8MB size cap, 10-per-item count cap, randomized stored filename, files stored OUTSIDE the public area under uploads/gas-private/ with a deny-all .htaccess/index.php/web.config, served only through an authorized REST endpoint (never a direct URL, never executed), SVG/exe/script payloads rejected, optional ClamAV/AV verdict via the gsm_attachment_scan filter, path-traversal-guarded streaming, and delete limited to a manager or the original uploader (IDOR-safe). New routes: POST /gas/v1/attachments, GET /gas/v1/attachments/list/{entity}/{id}, GET /gas/v1/attachments/{id}, DELETE /gas/v1/attachments/{id}.
* Communication & alerts ("תקשורת והתראות", manager): daily quarterly-due email reminders to managers (WP-Cron), immediate email alert on urgent faults, broadcast a message to an audience (managers / lab managers / PIs / אחמ"ת / all), test-email button, and a delivery log. Optional WhatsApp/SMS via an external webhook — the token is stored server-side only and never returned to the client (masked as whatsapp_has_token). New routes: GET/POST /gas/v1/comm, POST /gas/v1/comm/broadcast, POST /gas/v1/comm/test.
* Public status dashboard: a shareable, token-protected page showing aggregate numbers only (no personal data) — total equipment, scan coverage, open faults, overdue quarterly. New route GET /gas/v1/bi/public (aggregate-only).
* Security hardening: security response headers on the front-end (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Cross-Origin-Opener-Policy) and no-store headers on file streams; transient-based per-IP rate limiting on uploads, downloads, broadcast, and the public endpoint. (CSP/HSTS are recommended at the web-server level.)
* Database: adds wp_gas_attachments and wp_gas_comm_log (DB v8); created automatically on upgrade.

= 2.12.0 =
* Auto panel numbering ("מספור פאנל", manager): assign sequential panel numbers to a group of equipment — filter by building, optional prefix, start number, and an overwrite-existing option. New POST /gas/v1/equipment/assign-panels.
* Contact library ("אנשי קשר", manager): a screen listing every person referenced on equipment (PI / lab manager / maintenance) with how many items each is on, plus bulk tools to assign a person to a group (by building) and to rename or remove a person across all equipment. New GET /gas/v1/contacts, POST /gas/v1/contacts/assign and /contacts/replace.
* Branding ("מיתוג" in site settings): set the system title, the home-screen subtitle, and an optional header logo URL; the header and home screen use them (stored in gsm_branding, served to the app via config).
* Menu ordering ("סידור תפריט" in site settings): reorder navigation items within their groups with up/down controls; saved (gsm_nav_order) and applied for everyone. The four new screens were also added to the role-permission / show-hide matrix.
* No database change (DB stays v7).

= 2.11.0 =
* Undo delete (ביטול מחיקה): every deleted equipment / fault / inspection was already captured in the audit log (before_json); the audit screen ("יומן בקרה") now shows a "↩ שחזר" button on each delete entry that re-creates the record (with its original id when free) and marks the entry restored. New POST /gas/v1/audit/{id}/undo (manager). Note: an inspection's row is restored but its per-item checklist results are not.
* Restore points (נקודות שחזור): a new manager screen that takes full data snapshots (equipment, faults, inspections, inspection items + the inspection config) and keeps the last 20. Each point can be restored, downloaded as JSON, or deleted. Restore is NON-destructive — it re-applies the snapshot with REPLACE (upsert by id), so it brings back deleted/changed rows but never deletes rows created after the snapshot, and it always takes an automatic safety snapshot of the current state first. New table gas_restore_points → DB upgrades to v7 (adds one table; existing data is untouched). New /gas/v1/restore-points endpoints (manager).

= 2.10.0 =
* "הציוד שלי" (My Equipment): a new screen (nav "ציוד ומערכות → הציוד שלי", visible to all users) that lists only the equipment the signed-in user is linked to — as PI, lab manager, maintenance, or contact — matched by their WordPress name (whole-word and order-independent; a multi-word name needs at least 2 of its words to match, which separates people who share one name) or by their email. New GET /gas/v1/equipment/mine (report capability); read-only and adds no privilege, since users can already list all equipment.
* Dashboard — scan coverage & monthly overdue: a new card shows 30-day scan coverage (scanned/total and %), how much equipment was not scanned in the last 30 days, how many items are overdue for their monthly inspection (per the configured monthly cadence), and a 14-day scan-trend bar chart (bundled Chart.js). The /dashboard payload gains a "coverage" block. No database change (DB stays v6).

= 2.9.0 =
* Checklist Excel export: the "הגדרות ביקורת" (inspection settings) screen now has a "⬇️ ייצוא צ׳ק-ליסטים" button that downloads an .xlsx with BOTH role checklists (monthly + quarterly) in one sheet — columns: inspection type, number, check item. A matching card was added to the "הנפקת דוחות" export center. Server: new GET /gas/v1/export/checklists (report capability), built with the existing GSM_Xlsx writer and the same base64→Blob download flow as the other reports. No database change (DB stays v6).

= 2.8.3 =
* Fix: the intermittent "בדיקת עוגיות נכשלה / Cookie check failed" error that forced a manual page refresh. Its two root causes are fixed: (1) the login response now returns a nonce that is valid for the new session (the logged-in cookie is applied within the same request before the nonce is generated), so the first action right after signing in no longer fails; (2) the app now recovers from an expired, rotated, or cached nonce automatically — on that error it fetches a fresh nonce (via admin-ajax, which needs no REST nonce) and retries the request once, transparently. The REST nonce check runs before the route handler, so a rejected call never ran and the retry cannot double-submit. A background heartbeat also refreshes the nonce on a timer and whenever the tab regains focus, so an idle tab stays usable. No database or permission change (DB stays v6).

= 2.8.2 =
* Branding: the Safety Unit logo (יחידת הבטיחות / Safety Unit) now replaces the generic shield icon in the app header and on the home/login screens. The logo is bundled with the plugin and served from the site itself (same origin) in two forms — a transparent version that sits cleanly on the navy header bar, and the full navy badge on the white home/login cards — so it renders correctly even when outside CDNs are blocked. No database or behaviour change (DB stays v6).

= 2.8.1 =
* Fix: QR codes were blank and BI charts were missing on the Technion network, because the QR library (qrcodejs) and Chart.js were loaded from an outside CDN (cdnjs) that the network blocks. Both libraries are now bundled with the plugin and served from the site itself (same origin), so QR stickers and charts render without any external access. No CDN is required anymore.

= 2.8.0 =
* Equipment import from Excel/CSV with column mapping ("ייבוא ציוד", manager): upload an .xlsx or .csv, map each column to a field, preview, and import. Rows upsert through the validated equipment service (create new / update existing by a chosen key field — asset code, Monday ID or name) and are written to the audit log. CSV and XLSX are parsed server-side with no external library (self-contained; works even when outside CDNs are blocked); Hebrew handled for both (XLSX recommended). Secure upload: 5MB cap, extension+MIME check, parsed from the temp file and never stored publicly or executed, with zip-bomb guards for XLSX. New REST /import/analyze + /import/equipment (manager-only).

= 2.7.1 =
* Fix (RTL): the grouped-nav dropdown for the right-most menu ("סקירה וניתוח") opened off the right edge of the screen and clipped its items. Menus now anchor to their inline-start edge and open inward (with a viewport max-width), so every dropdown stays on screen.
* Fix (reports): the Excel export now runs inside a try/catch and raises the memory/time limits, so a failure returns a readable error (the real reason + file:line) instead of WordPress's generic "critical error" page, and large datasets are less likely to hit a resource limit.

= 2.7.0 =
* QR issuance manager ("ניהול הנפקות", manager + אחמ"ת): track which equipment got a QR label across three stages — issued / pending reissue / archived — with search, status tabs, move-between-stages, delete (archived only), single add, and a "sync from equipment" bulk that creates a record for every item that lacks one (idempotent). New gas_qr_issuance table (DB v6). REST /qr-issuance (+ /{id}/status, /bulk). Gated by new GSM_Auth::require_issuance.
* Internal database browser ("מסד נתונים פנימי", manager): browse / search / paginate / CSV-export any of the system's own tables (equipment, inspections, faults, QR issuance, scans, inspection items, audit, checklist templates, deletion requests). Table names come from a hard-coded whitelist; qr_token and ip_hash are never shown or exported; long cells are truncated multibyte-safely in the grid. Editing is done through the validated, audited forms (equipment/faults/inspections); log tables are read-only by design (so the audit trail can't be tampered with). REST /db/tables, /db/table/{key}, /db/export/{key} (manager-only).

= 2.6.0 =
* BI & analytics screen ("BI וניתוח", manager): equipment breakdowns by status / gas type / building and the top 10 PIs, each as a Chart.js chart with a full data table beneath (the table is the accessible source of truth and the fallback if the chart library is blocked). REST GET /bi/advanced.
* Integrations screen ("אינטגרציות", manager): outgoing signed webhook (HTTP POST on fault/inspection/equipment events; optional HMAC-SHA256 signature in X-Gas-Signature; non-blocking so a slow endpoint never delays the user). REST /integrations.
* Token-gated data export for Power BI / Excel / calendars: GET /bi/data/{equipment|faults|inspections} as CSV or JSON, and GET /bi/ical (quarterly inspection calendar), authorized by a rotatable secret BI token (GET /bi/token, POST /bi/token/rotate). The secret is never sent to the browser except to the manager who owns it; rotating it instantly invalidates old links.
* No new tables (BI token + integrations config are stored as options); DB stays at v5.

= 2.5.0 =
* System & Settings (part 1): role-permissions matrix (which nav items each role sees) + global menu show/hide, applied live to the navigation. REST /settings/role-perms, /settings/menu.
* Full profile: avatar (emoji) + Amendment-13 deletion request (records request, clears phone/avatar, emails admin). New gas_deletion_requests table (DB v5).
* Help turned into an accordion per tool. New "אבטחה" screen with guidance to use audited WP 2FA/passkey/CAPTCHA plugins (no self-rolled crypto).

= 2.4.0 =
* Grouped dropdown navigation (6 menus) mirroring the portal: סקירה וניתוח / ציוד ומערכות / תקלות וביקורות / QR והנפקות / מערכת והגדרות. Only built tools are shown.
* New tools: הנפקת דוחות (reports center), טיוב מידע (data-quality scan), הפרופיל שלי (name/phone), עיצוב עמוד סריקה (bridge design — fields/buttons/colors, read by the bridge), עזרה ומדריך.
* Audit log (יומן בקרה): new gas_audit table (DB v4) + central logging on create/update/delete of equipment/faults/inspections; manager view. REST /audit, /quality, /profile, /bridge-config.

= 2.3.1 =
* Fix: a completed QUARTERLY inspection now advances the equipment's dates (last_quarterly = inspection date, next_quarterly = + cadence), so the dashboard deadline actually resets. Monthly/adhoc leave quarterly dates untouched (mirrors the old Worker's recordInspectionDates).

= 2.3.0 =
* Role↔inspection model: new gsm_ahmat role (quarterly, long checklist); lab manager/PI do monthly (short checklist); manager both. Server enforces type↔role on inspection create.
* Inspection form auto-loads the correct checklist for the user's role.
* "הגדרות ביקורת" screen (manager): edit quarterly/monthly checklists + reminder rules (gsm_inspection_config). REST /inspection-config.
* "ניהול משתמשים" screen (manager): create/manage staff, change role, activate/deactivate, email password reset. New gsm_manager role (not a WP admin). REST /users.

= 2.2.0 =
* Excel export (reports): download buttons on equipment/faults/inspections + a reports card on the dashboard (incl. scans). Real .xlsx via ZipArchive with CSV fallback. New GET /export/{type}.
* Full equipment form: add/edit now exposes all ~25 fields, so migrated data is fully editable.
* Backup/export: the Gas Migration page can download all data as JSON in the import format (round-trips via the file importer).
* Public fault reporting fixed: anyone who scans a QR can now report a fault (new public POST /report-fault, authorized by qr_token). Anti-spam: honeypot + time-trap + per-IP rate limit, no third party.

= 2.1.1 =
* Fix: all tabs except the dashboard returned "No route was found" on sites using "Plain" permalinks. The SPA appended query strings (?per_page=…) which broke the ?rest_route= scheme. The api() helpers now join query params correctly under any permalink setting.
* Fix: QR links are now built from the bridge page's real permalink (passed from PHP), so they work under pretty or plain permalinks.
* Recommended: set Settings → Permalinks to "Post name".

= 2.1.0 =
* Offline import: new "ייבוא מקובץ (JSON)" uploader on the Gas Migration admin page — for networks that block the live browser-Console migration. Upload a JSON exported from the portal; equipment, inspections and faults are imported server-side (no outside access needed).
* Importer is idempotent (upsert by monday_id) and routes every write through the service layer (validation, asset_code = Monday number, qr_token). Inspections/faults link to equipment by Monday id, else by equipment name.
* Companion portal export script produces the JSON (and an optional equipment SQL file for phpMyAdmin).

= 2.0.0 =
* Asset numbering (asset_code): migrated items keep their Monday number; new equipment gets a fresh sequential G-#### number. DB v2 + one-time backfill (monday_id -> asset_code).
* Batch QR sticker generator ("מדבקות QR" tab): multi-select equipment, pick a label size, print an A4 sheet of labels (asset number + QR + name + location).
* POST /qr/prepare (manage): backfills QR tokens + asset codes so no label is blank.
* asset_code surfaced in equipment list/detail/form and the public bridge page.
* Runtime enabled by default (full Monday replacement); build-time scaffolding notice replaced with a quiet status notice.

= 0.7.0 =
* Full UI (dashboard, equipment, faults, inspections) via [gas_app] shortcode.
* Create/edit forms for equipment, faults, inspections (with checklist).
* QR bridge endpoint + scan logging.
* Companion theme with portal design tokens.
* Passed 4-pass QA (syntax, logic, integration, security).

= 0.4.0 =
* Monday migration mechanism.

= 0.3.0 =
* REST API + authorization.

= 0.1.0 =
* Initial scaffolding + database schema.
