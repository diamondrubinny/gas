# Gas Safety — מצב הריפו

## תוכן
| רכיב | נתיב | גרסה |
|---|---|---|
| תוסף WordPress | `plugins/gas-safety-manager/` | 2.13.2 (DB v8) |
| ערכת עיצוב | `themes/gas-safety-theme/` | 1.2.0 |

יובא מקבצי ה-ZIP שהועלו (2.13.0); מאז תוקן הממצא הגבוה (2.13.1) ובוצעה סקירת XSS (2.13.2).

## בדיקות שבוצעו (2026-11-10)
- `php tests/bi-token-test.php plugins/gas-safety-manager` — 20/20.
- `php tests/csv-injection-test.php plugins/gas-safety-manager` — 14/14.
- `node tests/public-dashboard-test.js plugins/gas-safety-manager/public/gas-public-dashboard.html` — 7/7.
- `php -l` על כל קבצי ה-PHP (PHP 8.3) — נקי.
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

### 🟡 לבדיקה
- שאילתות SQL עם interpolation — נבדקו מדגמית; שמות הטבלאות מגיעים מ-whitelist/prefix ולא מקלט המשתמש. ה-`ORDER BY` ב-`class-gsm-rest-db.php` מגיע מה-registry הקשיח.
- טוקנים ב-query string (`?token=`) עלולים להירשם בלוגים של השרת או של proxy.

## הצעד הבא המומלץ
1. ~~תיקון הממצא הגבוה~~ — בוצע (2.13.1). נשאר לבדוק ידנית על סביבת staging.
2. ~~סקירת XSS מלאה~~ — בוצע (2.13.2). השלב הבא: refactor של `onclick` inline ואז CSP.
3. הוספת `.gitignore`, `README.md` ו-CI בסיסי (`php -l`, `node --check`, PHPCS).
