# Content-Security-Policy — Gas Safety Manager (2.13.5)

## מה הוגדר
CSP נשלחת **רק** בעמודי המערכת: העמוד עם `[gas_app]` ועמוד ה-QR עם `[gas_bridge]`. שאר עמודי האתר ונקודות ה-REST לא מושפעים.

```
default-src 'self';
script-src 'self' 'nonce-<random per request>';
style-src 'self' 'unsafe-inline';
font-src 'self' data:;
img-src 'self' data: blob: https:;
connect-src 'self';
frame-src 'self';
object-src 'none';
base-uri 'self';
form-action 'self';
frame-ancestors 'self';
upgrade-insecure-requests   (רק ב-HTTPS)
```

- **`script-src` קשיח:** אין `unsafe-inline` ואין `unsafe-eval`. גם אם תיכנס בעתיד פרצת XSS, `<script>` מוזרק או `onclick=""` מוזרק לא ירוצו. זה נבדק בפועל בבדיקת ה-E2E.
- **nonce:** WordPress מוסיף אותו לכל `<script>` שהוא מדפיס, דרך הפילטרים `wp_script_attributes` / `wp_inline_script_attributes`. אימתתי את זה בקוד המקור של WP 6.4.5 ובהרצה על WP 7.1.4. זה כולל את `window.GSM_CONFIG` ואת סקריפטי הליבה (emoji, speculationrules).
- **`style-src 'unsafe-inline'`:** נשאר כי הממשק משתמש ב-`style=""`. CSS לא מריץ קוד, ולכן זה לא מחליש את ההגנה מפני XSS.
- **`img-src https:`:** נדרש ללוגו חיצוני אופציונלי (מיתוג).
- **אין מארחים חיצוניים:** מגרסה 2.13.5 הפונט Rubik מגיע עם התוסף (בלי Google Fonts), וסקריפט ה-emoji של WordPress כבוי בעמודי המערכת (הוא טען תמונות מ-`s.w.org`). לכן הוסרו גם `fonts.googleapis.com`, `fonts.gstatic.com` ו-`worker-src blob:`. בדיקת ה-E2E נכשלת על כל בקשה למארח חיצוני.

## לוח המצב הציבורי
הקובץ `public/gas-public-dashboard.html` סטטי, ולכן ה-CSP שלו מוגדרת ב-`<meta>`:
`default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'`.
הסקריפט הועבר ל-`public/assets/js/public-dashboard.js`.

## מתג הפעלה (לפריסה ולתקלות)
ב-`wp-config.php`:
```php
define( 'GSM_CSP_MODE', 'report-only' ); // enforce (ברירת מחדל) | report-only | off
```
אפשר גם דרך הפילטר `gsm_csp_mode`. ערך לא חוקי נופל ל-`enforce`.
את ה-directives אפשר להרחיב דרך `gsm_csp_directives` (מערך של directive => sources, והפילטר מקבל גם את ה-nonce).

**מתי להשתמש ב-report-only:** אם תוסף צד שלישי באתר (אנליטיקס, צ'אט, באנר עוגיות) מדפיס `<script>` inline בעמודי המערכת בלי לעבור דרך ה-API של WordPress, ה-CSP תחסום אותו. ב-report-only הדפדפן רק מדווח בקונסול (F12), בלי לחסום.

## Refactor
- כ-25 מטפלי `onclick=""` הוחלפו ב-`data-gsm-nav="/route"` / `data-gsm-action="name"`, עם מאזין מואצל אחד ב-`router.js` (`GSM.registerAction`).
- **חלונות ההדפסה** (QR וגיליון מדבקות) יורשים את ה-CSP של העמוד הפותח, ולכן אין בהם יותר סקריפט inline. החלון הראשי קורא ל-`print()` וחובר לכפתור "הדפס".
- ב-`bridge.js`, כפתור "סגור" עובד עכשיו דרך `addEventListener`.
- בערכת העיצוב 1.2.1, `onerror` על הלוגו הוחלף בבדיקה בצד השרת.

## בדיקות
`tests/e2e/csp-e2e.js` רץ מול WordPress אמיתי:
```bash
BASE=http://127.0.0.1:8899 USER_NAME=admin PASS='<password>' node tests/e2e/csp-e2e.js
```
מה הבדיקה מכסה:
- כותרת ה-CSP.
- התחברות.
- כל 25 המסכים.
- כל הכפתורים שהומרו: ניווט, תפריט, ייצוא, בחירת טקסט, נקודות שחזור, ביטול מחיקה, יציאה.
- שני חלונות ההדפסה.
- עמוד ה-QR ודיווח תקלה.
- לוח המצב הציבורי מקישור השיתוף, כולל אימות שהטוקן הציבורי מקבל 403 ב-`/bi/data`.
- חסימה בפועל של קוד מוזרק.
- **0 הפרות CSP ו-0 שגיאות JS** בשימוש רגיל.

## באגים קודמים שהתגלו בבדיקה ותוקנו
1. **ייצוא Excel קרס** (`wp_tempnam()` undefined) באתר שבו אף רכיב אחר לא טוען את `wp-admin/includes/file.php`.
2. **ה-time-trap של טופס התקלה** השתמש בשעון ברזולוציה של שנייה, ודחה לפעמים משתמשים אמיתיים ששלחו 2-3 שניות אחרי פתיחת הטופס.
