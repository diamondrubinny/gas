# סקירת XSS — Gas Safety Manager

- **תאריך:** 2026-11-10
- **גרסה שנסקרה:** 2.13.1. התיקונים נכנסו ב-2.13.2.
- **היקף:** כל קוד הלקוח (`public/assets/js/**`, `gas-public-dashboard.html`), כל פלט ה-PHP לדפדפן (תוסף + ערכת עיצוב), קובצי ייצוא (CSV/XLSX/iCal), הזרקת config inline, הגשת צרופות ומיילים.

## שיטה
1. סריקה אוטומטית של כל שרשור משתנה לתוך מחרוזת HTML שלא עובר דרך `esc()` (159 מועמדים).
2. מעבר ידני על כל מועמד, כולל מקור הערך: שרת / משתמש / קבוע בקוד.
3. בדיקת sinks נוספים: `href`/`src`/`style`/`onclick` עם נתונים, `document.write`, `w.document.write` בחלונות הדפסה, `wp_add_inline_script`, ו-`echo` ב-PHP.
4. בדיקת הסניטציה בצד השרת לערכים שנכנסים להקשרי attribute/CSS: צבעים, לוגו, שם שולח.

## תוצאה: לא נמצא XSS שניתן לנצל
- **`esc()`:** כל ערך ממקור לא-קבוע (שם ציוד, תיאור תקלה, שמות אנשי קשר, כותרות עמודות מקובץ ייבוא, נתוני מסד נתונים גולמיים, יומן בקרה) עובר דרך `esc()`. הפונקציה מקודדת `& < > " '`, ולכן בטוחה גם בתוך attributes.
- **ערכים לא-מקודדים שנשארו** (מזהי DB, ספירות, מחלקות CSS) מגיעים משאילתות מספריות או ממפות קבועות בקוד.
- **צבעים** עוברים `sanitize_hex_color`, ו**לוגו** עובר `esc_url_raw`.
- **ערכת העיצוב** משתמשת ב-`esc_url` / `esc_html` / `esc_attr` לאורך כל הקוד.
- **מיילים** נשלחים כ-plain text. שם השולח עובר `sanitize_text_field`, שמסיר שורות חדשות, ולכן אין header injection.
- **צרופות:** רשימת היתר כוללת רק JPEG/PNG/WebP/PDF (בלי SVG/HTML), עם בדיקת MIME אמיתי, `nosniff`, והן מוגשות רק דרך REST מורשה.
- **XLSX** נכתב כ-`inlineStr`, ולכן נוסחאות לא מורצות.

## ממצאים שתוקנו (2.13.2)

| # | חומרה | ממצא | תיקון |
|---|---|---|---|
| 1 | בינוני | **CSV formula injection (CWE-1236):** תיאור תקלה מדיווח אנונימי בעמוד ה-QR, למשל `=HYPERLINK(...)` או DDE, נכנס לייצוא CSV ומורץ כשמנהל פותח אותו ב-Excel. | `GSM_Security::csv_cell()` מוסיף גרש לפני תאים שמתחילים ב-`= + - @` או ב-Tab/CR. ההגנה פועלת בכל נתיבי ה-CSV: BI, מסד נתונים, וגיבוי ה-Excel. מספרים רגילים לא משתנים. |
| 2 | בינוני-נמוך | **לוח המצב הציבורי:** הפרמטר `?api=` נלקח מה-URL בלי הגבלה. קישור מזויף גרם לדף שעל הדומיין המהימן להציג נתונים מזויפים משרת זר (content spoofing) ולשלוח אליו את הטוקן. | רק כתובת מאותו origin מתקבלת. אחרת הדף חוזר ל-`/wp-json/gas/v1`. |
| 3 | נמוך (הקשחה) | config inline (`GSM_CONFIG` / `GSM_BRIDGE`) קודד בלי `JSON_HEX_TAG`. | נוספו `JSON_HEX_TAG|AMP|APOS|QUOT`. |
| 4 | נמוך (הקשחה) | ב-`router.js` הודעת שגיאה הוכנסה ל-`innerHTML` בלי קידוד. | ההודעה מוצגת עכשיו כ-`textContent`. |
| 5 | נמוך (הקשחה) | `#/equipment/<x>` הועבר כמו שהוא לנתיב ה-API (client-side path/query injection). | רק מזהה מספרי מתקבל. |

## בדיקות
- `php tests/csv-injection-test.php plugins/gas-safety-manager`: 14/14.
- `node tests/public-dashboard-test.js plugins/gas-safety-manager/public/gas-public-dashboard.html`: 7/7. אותה בדיקה **נכשלת** על הקובץ המקורי (5 כשלונות), כלומר היא תופסת את הבאג.
- `php tests/bi-token-test.php plugins/gas-safety-manager`: 20/20, בלי רגרסיה.

## המלצות שלא יושמו (דורשות החלטה)
1. **Content-Security-Policy:** האפליקציה משתמשת בהרבה `onclick="..."` inline, ולכן CSP קשיח (בלי `unsafe-inline`) דורש קודם refactor שיחליף אותם ב-`addEventListener`. זו שכבת ההגנה החשובה הבאה.
2. **Google Fonts בחלון הדפסת מדבקות:** החלון נטען מ-`fonts.googleapis.com`, וזה שולח את כתובת ה-IP של המשתמש לגוגל. זו סוגיית פרטיות/GDPR, וברשת הטכניון הכתובת חסומה ממילא. מומלץ להחליף לפונט מקומי.
3. **PDF inline:** קובצי PDF מוצגים inline. הצופה של הדפדפן מבודד, אבל אפשר לשקול `Content-Disposition: attachment` ל-PDF.
4. **טוקנים ב-URL** (`?token=`, `_wpnonce=`) עלולים להירשם בלוגים של השרת. מומלץ לוודא שהלוגים לא נשמרים לאורך זמן.
