=== Gas Safety Manager ===
Contributors: diamondrubinny
Tags: safety, equipment, inspections, hebrew, rtl
Requires at least: 6.4
Requires PHP: 8.2
Tested up to: 7.1
Stable tag: 2.20.0
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

= 2.20.0 =
* Users: import from a file ("ייבוא מקובץ" on the users screen) — the old system's users export (JSON) or a CSV / Excel sheet with name, e-mail, role, status. Preview first (nothing is created), then create. Old PIN codes / PIN hashes are ignored and never stored: each new user gets a random password and, optionally, an e-mail with a link to choose one. Existing accounts are never changed. Up to 500 rows, 2 MB. POST /users/import (manager).
* Tests: tests/integration/users-import-test.php, tests/e2e/users-import-e2e.js.

= 2.19.0 =
* Public dashboard: additional named links (one per recipient, up to 20). Each has its own token, can be revoked on its own, and shows how many times it was loaded and when last. Only a counter and a time are kept — no visitor data. A named link opens the aggregate dashboard only, never the row-level export or the calendar feed.
* Dashboard: personal layout — each user chooses which sections to show and in what order ("התאמת הדשבורד"), saved in their account.
* SAP / ERP export: `format=sap` on the BI export (semicolon-separated CSV, UTF-8 BOM, CRLF), with links on the integrations screen.
* "איפוס סטטיסטיקת סריקות" (site settings, manager): deletes all scan records after a double confirmation; written to the audit log.
* Accessibility menu: a floating button on every front-end page (app, QR page, site) — text size, high contrast, highlighted links, readable font, stop animations, reset. Keyboard operable; choices are kept in the visitor's browser only. A convenience on top of the accessible markup, not a replacement. Disable with `add_filter( 'gsm_a11y_widget', '__return_false' );`.
* Menu: an icon for each group (decorative, hidden from assistive technology).
* New routes: /bi/public-links (+ /{id}), /dashboard/layout, /retention/reset-scans.
* Tests: tests/integration/public-links-test.php, tests/e2e/gaps-e2e.js (37 checks incl. axe with all accessibility adjustments on).

= 2.18.0 =
* Dashboard: "הודעות מהשטח" — any signed-in user sends a short message to the managers; managers see all messages with a "new" badge, mark them read, delete, or clear. Other users see only their own messages and whether they were read.
* Dashboard: "משוב ובקשות שיפור" — bug / improvement request / question with priority, title and area; managers move the status (open, in progress, done, rejected) and the author sees it. Filter: active / all.
* Both are plain text (sanitized, escaped on output), limited to 2,000 characters and 20 per user per hour. Included in the retention policy (handled items, 24 months by default), in privacy requests (author / title / text are scanned and anonymized), in the database browser and in uninstall. No e-mail is sent for them.
* New routes: /field-messages (+ /{id}/read, /{id}, /clear), /feedback (+ /{id}/status, /{id}).
* DB v10 (new table gas_messages, created automatically on update).
* Tests: tests/integration/messages-test.php (permissions, isolation between users, retention, privacy), tests/e2e/messages-e2e.js.

= 2.17.1 =
* Equipment: two new fields — purchase date ("תאריך רכישה") and manual link ("קישור לחוברת", https only). In the equipment form, the equipment page (link opens in a new tab), Excel import and export, restore points, and the pull from the old system (these two old columns were skipped until now; a non-https link is dropped without failing the row).
* DB v9 (two columns added to the equipment table automatically on update).
* Fix (important): every form (equipment, fault, inspection, user) and attachment upload/download used the security nonce baked into the page. After logging in inside the app without reloading the page, the first save failed with "Cookie check failed". Forms now use the session's current nonce and refresh it once when it expired.
* Fix: after a validation error in a form the "שמור" button stayed disabled, so the form had to be cancelled and filled again.
* Tests: tests/integration/equipment-fields-test.php, tests/e2e/forms-e2e.js (real form saves and an attachment upload right after login).

= 2.17.0 =
* Built-in two-factor authentication (TOTP, RFC 6238) — works with Google Authenticator, Microsoft Authenticator, Authy etc. No external service.
* Enrollment on the "אבטחה" screen: QR code (generated locally) + manual key, confirmation by code, 8 one-time backup codes shown once.
* Login: after a correct password the app asks for the code. Enforced in the core `authenticate` filter, so it also covers wp-login.php (extra field) and XML-RPC; application passwords are switched off for users with 2FA.
* Optional policy "mandatory for managers" (off by default; can only be turned on by a manager who already uses 2FA): un-enrolled managers are sent to the security screen and manager API calls are refused until they enroll.
* Security: secret stored encrypted (AES-256-GCM, key derived from the WordPress auth salt), backup codes hashed, each code accepted once (replay protection), 5 wrong codes lock the second step for 15 minutes, disabling needs password + code, enabling ends other sessions. A manager can reset another user's 2FA (ends that user's sessions). All events are written to the audit log.
* Recovery: backup codes, reset by another manager, or `define( 'GSM_2FA_DISABLE', true );` in wp-config.php.
* Passkeys and CAPTCHA are still not built in.
* Tests: tests/integration/twofa-test.php (RFC 4226/6238 vectors, all login paths, replay, lockout, policy; 44 checks), tests/e2e/twofa-e2e.js.

= 2.16.1 =
* QR issuance ("ניהול הנפקות QR"): every record now shows its scan link — the same URL printed in the sticker's QR code — with a copy button (as in the old portal). Records not linked to equipment say so. GET /qr-issuance returns each record's equipment qr_token (one query per page; the screen is limited to managers and אחמ"ת, who print the stickers).
* Accessibility: the group headings on that screen were coloured with the status colour (green failed contrast); the colour is now on the dot only.
* Test: tests/e2e/qrissue-e2e.js (link, clipboard, the link opens the right QR page, axe).

= 2.16.0 =
* New screen "משיכה מהאתר הישן" (manager): pull equipment, faults, inspections and the audit log from the old system (safety-qr-app / api.safety-tech.dev) through its read-only BI export, using the old system's BI token (no user PIN). A whole BI link can be pasted; host + token are extracted.
* Check first (dry run): fetches and recognizes everything, shows counts (new / updated / skipped), the column mapping, unrecognized columns and sample rows — writes nothing. "Pull now" is enabled only after a successful check.
* Pull: restore point first (aborts if it fails), then upsert by Monday id through the service layer — repeatable, never creates duplicates, never deletes. Empty old values never blank a field. Rows without a Monday id are skipped. Old QR stickers keep working (Monday-id lookup).
* Format recognition: raw Monday items (column_values), flat objects keyed by Monday column id or by Hebrew column title, and header/row tables. The column ids/titles come from the old portal's own code. Status/priority/type/result texts and dates are converted; the old checklist text (✓ ✗ —) becomes checklist items; faults/inspections are linked to equipment by name.
* Audit log: imported as an archive (not undoable), duplicates skipped, entries older than the audit retention period are not imported.
* Optional daily automatic sync (WP-Cron) while both systems run side by side; off by default, cleared on deactivation/uninstall. Last run (ok / error) is shown.
* Security: https host names only (no IPs / internal hosts; SSRF guard on the request), no redirects, 30 s timeout, 15 MB cap, 20,000 rows; token stored server-side, masked, never logged or returned, wiped when the host changes; error messages never include the URL/token; one run at a time.
* Bulk-import mode (also for the JSON file import): no webhook per row, no urgent-fault alerts for old records, one summary audit entry instead of one per row.
* Tests: tests/integration/legacy-pull-test.php (mock old server, 52 checks), tests/e2e/legacy-e2e.js (incl. the real old server rejecting a fake token).

= 2.15.1 =
* Fix: the system title in the navy header was navy-on-navy (contrast 1:1) whenever the active theme colours headings (the bundled theme does). The title is now white on both gradient stops.
* Fix: the dashboard "scans in the last 14 days" chart grew to 1,400–2,400 px tall (Chart.js with maintainAspectRatio:false inside an unsized box). It now sits in a fixed 220 px box (180 px on phones), with slimmer bars.
* Accessibility: that chart now has an accessible name and a text alternative listing the daily counts.
* Test: tests/e2e/layout-e2e.js (header contrast on both gradient stops, chart height, no horizontal scroll; desktop + mobile) — axe marks gradient backgrounds as "incomplete" and could not catch this.

= 2.15.0 =
* Privacy requests ("בקשות פרטיות", manager): handle personal-data deletion requests end to end (Israeli Privacy Protection Law Amendment 13 / GDPR erasure). Until now a request only cleared the phone and avatar; the person's name and email stayed in equipment contacts, inspections, faults, QR issuance, attachments, the delivery log, the audit log (incl. its JSON copies of deleted records) and the restore points.
* Scan first: finds every whole-word occurrence of the person's name / email / extra identifiers in all 23 locations, including inside JSON snapshots (where non-Latin text is \u-escaped) — a dry run that changes nothing.
* Then anonymize: each occurrence is replaced with "משתמש נמחק #N"; professional records (inspections, faults) are kept without the identifying text; other people in the same list field are left as they are; look-alike names ("…Levitz" for "…Levi") are not touched. Optionally deletes the WordPress account (gas-app users only). The request is closed and the audit log records the handling without the name or email. Requests can also be rejected.
* New routes: GET /gas/v1/privacy-requests, POST /gas/v1/privacy-requests/{id}/scan | anonymize | reject (manager).
* No DB change — stays v8.

= 2.14.2 =
* Requirements: PHP 8.2 or newer (PHP 8.1 reached end of life on 31 Dec 2025 — php.net). WordPress 6.4+ unchanged; tested up to WordPress 7.1. On a PHP 8.1 server WordPress refuses to install this update with a message (nothing breaks). Note: PHP 8.2 itself gets security fixes only until 31 Dec 2026 — plan the server upgrade to 8.3+.
* Libraries: Chart.js 4.4.1 → 4.6.1. The unmaintained qrcodejs is replaced by qrcode-generator 2.0.4 (MIT) with a small helper that encodes UTF-8 (the library's default keeps only 8 bits per character, which breaks non-Latin URLs) and outputs one PNG <img> with alt text. Every generated code is verified by decoding it (tests/qr-test.js).
* No DB change — stays v8.

= 2.14.1 =
Fixes from a code review of the modules not reviewed before (each verified in the code and covered by a test on a real WordPress):
* Security (high): a deactivated user kept full API access — the active flag was only checked at the app login. It is now enforced in every capability check; a deactivated account cannot sign in by any path (checked after the password, so it is not a user-enumeration oracle); deactivating logs the user out of all sessions.
* Security: the WhatsApp gateway token / webhook signing secret no longer follow the URL to a different host (a manager could redirect the token to their own server). Outbound calls are blocked from internal and reserved addresses (SSRF: localhost, private networks, 169.254.169.254 metadata — WordPress 6.4 itself does not block the last one). WhatsApp URL must be https. Internal webhook listeners: define( 'GSM_ALLOW_INTERNAL_WEBHOOKS', true ).
* Security: a gas manager could change the role of any non-admin site user (editor, shop manager…) or send them a password reset. Now limited to gas-app users, and only the gas role is swapped.
* Security: login lockout counter is cleared only after a fully successful app login; a non-app account is signed out again.
* Fix: undoing a deleted inspection restored it without its checklist items; undo of a deleted QR issuance was offered but always failed.
* Fix: restoring a restore point duplicated checklist items of edited inspections; a restore now aborts if the safety backup could not be saved (it used to continue without one).
* Fix: Excel/CSV import update wiped existing values for empty cells; CSV cells with line breaks were split into extra rows; Hebrew CSV in Windows-1255 (Excel default) crashed the import on PHP 8; XLSX cells without the optional "r" attribute were skipped.
* Fix: JSON backup re-import created duplicate equipment (now matched by asset code) and linked inspections/faults by non-unique name (now by asset code).
* Fix: a control character in a cell produced an Excel file that would not open; saving the profile without a phone field wiped the phone; broadcasts and manager alerts went to deactivated users; no cap on rows per import / bulk QR request.
* No DB change — stays v8.

= 2.14.0 =
* Chat widget (Botwa): optional chat button on the public QR page, inside the app and on the other site pages (each placement can be switched on or off). Set up in "הגדרות אתר" by pasting the provider's embed snippet; only an https host name and a key in the provider's format (wc_…) are accepted. Off by default.
* Security: the provider's loader script is served from the plugin as a reviewed, pinned copy (public/assets/vendor/botwa-widget.js) — no third-party script runs on the plugin's pages and the CSP keeps script-src 'self'. Only the chat window (a cross-origin iframe) and its JSON config come from the chat server; connect-src / frame-src allow that one host, only on pages that show the chat.
* Privacy: click-to-load — nothing is requested from the chat provider until a visitor clicks the chat button. The plugin never sends the user's name, email or phone to the provider (BotwaChat.identify() is not used). Messages typed in the chat do go to the provider: a data-processing agreement and a privacy-policy update are needed.
* Privacy: no Gravatar on the app and QR pages — a logged-in WordPress user's admin bar loaded their avatar from gravatar.com (third party: email hash + IP). Caught by the new "no third-party requests" check for logged-in users. (The companion theme 1.2.4 does the same on the other site pages.)
* New routes: GET/POST /gas/v1/chat (manager).
* No DB change — stays v8.

= 2.13.9 =
* Data retention ("מדיניות שמירת מידע" in הגדרות אתר, manager only): old records are deleted automatically once a day — QR scan log 24 months, delivery log 24 months, audit log 84 months, handled deletion requests 24 months (conservative defaults, adjustable; 0 = keep forever). Open deletion requests are never removed. Each cleanup is written to the audit log; the screen shows how many rows the next run would delete, plus a "run now" button with confirmation.
* Safety: automatic cleanup starts only 7 days after this version is installed, so nothing is deleted on upgrade day — time to review the periods. Deletion is irreversible (these logs are not part of restore points).
* Fix: deleting the plugin left personal data behind — 6 of 12 tables (audit log, restore points, delivery log, deletion requests, QR issuance, attachments), the private attachment files, user meta (phone, avatar), several options, 2 roles and capabilities. Uninstall now removes all of it (unless gsm_keep_data_on_uninstall is set). App pages are left as site content.
* New routes: GET/POST /gas/v1/retention, POST /gas/v1/retention/run (manager).
* No DB change — stays v8.

= 2.13.8 =
* Privacy fix: the public QR API (GET /gas/v1/qr/{code}, no login) returned the PI, lab managers, maintenance staff and phone even when the manager had hidden them on the scan page ("עיצוב עמוד סריקה") — hiding was UI-only. The API now returns contact fields only when they are shown. New toggle: "הצג טלפון" (default on, so nothing changes until you switch it off).
* Rate limits on the public QR API: 60 lookups / 5 min per IP, and 20 failed lookups / 10 min per IP — legacy printed labels use numeric Monday IDs, which are not secret, so this blocks enumerating them to harvest equipment and contact data.
* Docs: personal-data map (docs/privacy-data-map.md) and pre-production checklist (docs/production-checklist.md), built from the code.
* No DB change — stays v8.

= 2.13.7 =
* Security / privacy: access tokens are kept out of URLs where clients allow it, so they are not written to web-server or proxy access logs and never leak through a Referer.
* Public status dashboard: the share link now carries the token in the URL fragment (#t=…), which browsers never send to the server; the page sends it in an X-GSM-Token header. Older ?t=… links keep working and are rewritten to #t=… on open.
* BI exports (/bi/data): accept the token in the X-GSM-Token header (preferred, wins over ?token=). The Integrations screen shows the URLs without the token plus a ready Power Query (Excel / Power BI) snippet that sends the header. ?token= still works for existing links; the iCal feed keeps it because calendar subscriptions cannot send headers.
* No DB change — stays v8.

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
