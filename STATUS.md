# Gas Safety — מצב הריפו

## תוכן
| רכיב | נתיב | גרסה |
|---|---|---|
| תוסף WordPress | `plugins/gas-safety-manager/` | 2.13.4 (DB v8) |
| ערכת עיצוב | `themes/gas-safety-theme/` | 1.2.1 |

יובא מקבצי ה-ZIP שהועלו (2.13.0); מאז תוקן הממצא הגבוה (2.13.1) ובוצעה סקירת XSS (2.13.2) ונוספה CSP (2.13.3), ואחר כך PHPCS ו-CI (2.13.4). מדריך התקנה ופיתוח: [`README.md`](README.md).

## בדיקות שבוצעו (2026-11-10)
- `php tests/bi-token-test.php plugins/gas-safety-manager` — 20/20.
- `php tests/csv-injection-test.php plugins/gas-safety-manager` — 14/14.
- `node tests/public-dashboard-test.js plugins/gas-safety-manager/public/gas-public-dashboard.html` — 8/8.
- `node tests/e2e/csp-e2e.js` מול WordPress **6.4.5 (מינימום) ו-7.1.4** אמיתיים (SQLite) — הכל עובר, 0 הפרות CSP, 0 שגיאות JS. כולל צרופות (העלאה/הורדה/מחיקה מהדיסק/דחיית קובץ מזויף).
- `vendor/bin/phpcs` (אבטחה + תאימות PHP 8.1+) — 0 הפרות, 55 קבצים.
- actionlint + shellcheck על ה-workflow והסקריפטים — נקי.
- `php -l` על כל קבצי ה-PHP — נקי (ב-CI: PHP 8.1 עד 8.4).
- `node --check` על כל קבצי ה-JS (ללא vendor) — נקי.
- 62 קריאות `register_rest_route`.
- סריקת סודות קשיחים בקוד — לא נמצאו.

## ממצאי סקירת אבטחה ראשונית

### ✅ תוקן ב-2.13.1 — הטוקן של הדשבורד הציבורי פתח גם ייצוא נתונים מלא
- **הבעיה:** `/bi/public`, `/bi/data` ו-`/bi/ical` בדקו את אותו `gsm_bi_token`, כך שקישור הדשבורד ("מצטבר בלבד") אפשר הורדה של כל טבלת הציוד, כולל אנשי קשר, עם `CORS *`.
- **התיקון:** טוקן נפרד `gsm_public_token` רק ל-`/bi/public`, עם routes לניהול (`GET /bi/public-token` ו-`POST /bi/public-token/rotate`, למנהל בלבד) וכפתור החלפה במסך "תקשורת והתראות". `/bi/data` ו-`/bi/ical` מקבלים רק את טוקן ה-BI. נוספו rate limit (60 בקשות ל-5 דקות לכל IP), הסרת `CORS *`, כותרות no-store/noindex/nosniff/no-referrer, `referrer=no-referrer` בדף הדשבורד, רישום החלפות טוקן ביומן הבקרה וניקוי שני הטוקנים בהסרת התוסף.
- **בדיקה:** `tests/bi-token-test.php` (20/20).
- **השפעה בשדרוג:** קישורי דשבורד ציבורי ישנים מפסיקים לעבוד, וזה מכוון. אם הקישור הישן הופץ מחוץ לצוות, צריך להחליף גם את טוקן ה-BI.

### ✅ סקירת XSS מלאה (2.13.2)
לא נמצא XSS שניתן לנצל. תוקנו CSV formula injection (בינוני), `?api=` בלוח המצב הציבורי (בינוני-נמוך) ו-3 הקשחות. פירוט מלא: [`docs/xss-review.md`](docs/xss-review.md).

### ✅ CSP + refactor של onclick (2.13.3)
CSP קשיחה (`script-src 'self' 'nonce-…'`) בעמודי המערכת, ~25 מטפלי inline הוסרו, וחלונות ההדפסה בלי סקריפט inline. במהלך הבדיקה תוקנו גם 2 באגים קודמים: ייצוא Excel קרס (`wp_tempnam`), ו-time-trap שגוי בטופס התקלה. פירוט: [`docs/csp.md`](docs/csp.md).

### ✅ PHPCS + CI (2.13.4)
- **PHPCS אבטחה:** 28 הפרות נבדקו אחת-אחת. תוקנו:
  - `create-pages.php` ניתן להרצה ישירה ב-HTTP (קריסה וחשיפת נתיב כש-`display_errors` פעיל).
  - `REMOTE_ADDR` לא עבר סניטציה.
  - `unlink` הוחלף ב-`wp_delete_file`.
  השאר הם false positives שתועדו עם הסבר. ה-ruleset הזה חוסם ב-CI.
- **CI:** בדיקת תחביר ב-PHP 8.1 עד 8.4, PHPCS, בדיקות יחידה, E2E מול WP 6.4.5 ו-latest, ובניית ZIP. פירוט ב-[`README.md`](README.md#ci).
- **סגנון WordPress המלא** מדווח לידיעה בלבד: מאות הפרות פורמט, ש-PHPCBF מתקן כ-480 מהן אוטומטית. החלת התיקון היא החלטה נפרדת, כי היא תיצור diff גדול.

### 🟡 לבדיקה
- שאילתות SQL עם interpolation — נבדקו מדגמית; שמות הטבלאות מגיעים מ-whitelist/prefix ולא מקלט המשתמש. ה-`ORDER BY` ב-`class-gsm-rest-db.php` מגיע מה-registry הקשיח.
- טוקנים ב-query string (`?token=`) עלולים להירשם בלוגים של השרת או של proxy.

## הצעד הבא המומלץ
1. ~~תיקון הממצא הגבוה~~ — בוצע (2.13.1). נשאר לבדוק ידנית על סביבת staging.
2. ~~סקירת XSS מלאה~~ — בוצע (2.13.2).
3. ~~refactor של onclick + CSP~~ — בוצע (2.13.3). בפריסה: לבדוק בקונסול (F12) בעמודי המערכת שאין חסימות של תוספים אחרים; אם יש, להגדיר `GSM_CSP_MODE` ל-`report-only`.
4. ~~`.gitignore`, `README.md` ו-CI~~ — בוצע (2.13.4). יש לוודא שה-workflow רץ ירוק ב-GitHub Actions אחרי ה-push הראשון.
5. אופציונלי: הפעלת PHPCBF לתיקון פורמט אוטומטי (diff גדול, בלי שינוי התנהגות), נעיצת Actions ל-SHA, והחלפת Google Fonts בפונט מקומי (פרטיות).
