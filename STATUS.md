# Gas Safety — מצב הריפו

## תוכן
| רכיב | נתיב | גרסה |
|---|---|---|
| תוסף WordPress | `plugins/gas-safety-manager/` | 2.13.0 (DB v8) |
| ערכת עיצוב | `themes/gas-safety-theme/` | 1.2.0 |

יובא מקבצי ה-ZIP שהועלו, ללא שינוי בקוד.

## בדיקות שבוצעו (2026-11-10)
- `php -l` על כל קבצי ה-PHP (PHP 8.3) — נקי.
- `node --check` על כל קבצי ה-JS (ללא vendor) — נקי.
- 62 קריאות `register_rest_route`.
- סריקת סודות קשיחים בקוד — לא נמצאו.

## ממצאי סקירת אבטחה ראשונית

### 🔴 גבוה — הטוקן של הדשבורד הציבורי פותח גם ייצוא נתונים מלא
- `includes/rest/class-gsm-rest-bi.php`: `/bi/public`, `/bi/data/{set}` ו-`/bi/ical` כולם בודקים את **אותו** טוקן (`gsm_bi_token`).
- המשמעות: מי שמקבל את קישור הדשבורד הציבורי ("aggregate only, no personal data") יכול לקרוא ל-`/bi/data/equipment?format=json` ולקבל את כל טבלת הציוד, כולל שמות ואימיילים של אנשי קשר (PI / מנהל מעבדה / אחזקה), עם `Access-Control-Allow-Origin: *`.
- ל-`export_data` ול-`ical` אין rate limit (רק ל-`/bi/public` יש).
- **תיקון מוצע:** טוקן נפרד `gsm_public_token` לדשבורד הציבורי (עם כפתור סבב משלו), rate limit ל-`export_data`/`ical`, ושקילת הסרת `CORS *`.

### 🟡 לבדיקה
- שאילתות SQL עם interpolation — נבדקו מדגמית; שמות הטבלאות מגיעים מ-whitelist/prefix ולא מקלט המשתמש. ה-`ORDER BY` ב-`class-gsm-rest-db.php` מגיע מה-registry הקשיח.
- 107 שימושים ב-`innerHTML` ב-JS: יש פונקציות `esc()` ב-`bridge.js`/`forms.js`, אבל לא בדקתי כל שימוש — מומלצת סקירת XSS ייעודית.
- טוקנים ב-query string (`?token=`) עלולים להירשם בלוגים של השרת או של proxy.

## הצעד הבא המומלץ
1. תיקון הממצא הגבוה (גרסה 2.13.1).
2. סקירת XSS מלאה ב-`public/assets/js/views/`.
3. הוספת `.gitignore`, `README.md` ו-CI בסיסי (`php -l`, `node --check`, PHPCS).
