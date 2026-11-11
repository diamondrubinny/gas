# Gas Safety — מערכת ניהול תחזוקה למערכות גזים

תוסף וערכת עיצוב ל-WordPress עבור יחידת הבטיחות בטכניון: רישום ציוד, ביקורות חודשיות ורבעוניות, תקלות, מדבקות QR, דוחות Excel, BI ולוח מצב ציבורי.

| רכיב | נתיב | גרסה | דרישות |
|---|---|---|---|
| תוסף **Gas Safety Manager** | [`plugins/gas-safety-manager/`](plugins/gas-safety-manager/) | 2.16.0 (DB v8) | WordPress 6.4+, PHP **8.2+** |
| ערכת עיצוב **Gas Safety** | [`themes/gas-safety-theme/`](themes/gas-safety-theme/) | 1.2.6 | WordPress 6.4+, PHP **8.2+** |

היסטוריית שינויים: [`plugins/gas-safety-manager/readme.txt`](plugins/gas-safety-manager/readme.txt) (Changelog) ו-[`VERSION.txt`](plugins/gas-safety-manager/VERSION.txt). מצב הפרויקט וממצאים פתוחים: [`STATUS.md`](STATUS.md).

---

## התקנה ופריסה

1. בניית קובצי ההתקנה:
   ```bash
   scripts/build-release.sh
   ```
   הפקודה יוצרת את `dist/gas-safety-manager-v<גרסה>.zip` ואת `dist/gas-safety-theme-v<גרסה>.zip`. כל ריצה מוצלחת של ה-CI מעלה אותם גם כ-artifact בשם `gas-safety-zips`.
2. ב-WordPress: **תוספים ← הוספת תוסף ← העלאת תוסף**, ובחירה ב-"החלף את הקיים בגרסה שהועלתה". ערכת העיצוב מותקנת באותו אופן תחת **עיצוב ← ערכות עיצוב**.
3. בהפעלה הראשונה נוצרים אוטומטית הטבלאות והעמודים `/gas-app` (המערכת) ו-`/bridge` (עמוד ה-QR הציבורי). שדרוג גרסה מעדכן את מסד הנתונים אוטומטית ולא מוחק נתונים.
4. **לפני עדכון בסביבת הייצור**, מומלץ ליצור נקודת שחזור במסך "נקודות שחזור" ולבדוק את הגרסה בסביבת staging.

### אחרי הפריסה, לבדוק:
- **קונסול הדפדפן (F12)** בעמודי `/gas-app` ו-`/bridge`: אין שגיאות "Content Security Policy". אם תוסף אחר באתר נחסם, ראו [CSP](#מדיניות-אבטחת-תוכן-csp) למטה.
- **ייצוא Excel**, **הדפסת מדבקות QR** ו**לוח המצב הציבורי** (הקישור נמצא במסך "תקשורת והתראות").

---

## אבטחה

| נושא | מסמך |
|---|---|
| סקירת XSS והקשחות | [`docs/xss-review.md`](docs/xss-review.md) |
| מדיניות אבטחת תוכן (CSP) | [`docs/csp.md`](docs/csp.md) |
| נגישות (WCAG 2.1 AA / ת״י 5568) | [`docs/accessibility.md`](docs/accessibility.md) |
| מיפוי מידע אישי, ספקים ועוגיות | [`docs/privacy-data-map.md`](docs/privacy-data-map.md) |
| **רשימת בדיקות לפני פרודקשן** | [`docs/production-checklist.md`](docs/production-checklist.md) |
| ממצאים, תיקונים ופתוחים | [`STATUS.md`](STATUS.md) |

### מדיניות אבטחת תוכן (CSP)
בעמודי המערכת בלבד נשלחת CSP קשיחה (`script-src 'self' 'nonce-…'`). אם תוסף צד שלישי נחסם, אפשר להעביר אותה זמנית למצב דיווח בלבד, ב-`wp-config.php`:
```php
define( 'GSM_CSP_MODE', 'report-only' ); // enforce (ברירת מחדל) | report-only | off
```

### טוקנים
- **טוקן BI** (מסך "אינטגרציות") פותח ייצוא **מלא**, כולל פרטי אנשי קשר. יש לשתף אותו רק עם מערכות מורשות כמו Power BI ו-Excel.
- **טוקן לוח המצב הציבורי** (מסך "תקשורת והתראות") פותח **נתונים מצטברים בלבד**.
- החלפת טוקן מבטלת מיד את כל הקישורים הקודמים.
- **הטוקנים נשמרים מחוץ לכתובות ה-URL** ככל שהלקוח מאפשר, כדי שלא יירשמו ביומני השרת וה-proxy:
  - **בקישור לוח המצב,** הטוקן נמצא אחרי `#`. הדפדפן לא שולח את החלק הזה לשרת, והטוקן עצמו נשלח בכותרת `X-GSM-Token`.
  - **בייצוא ל-Power BI ו-Excel** מומלץ לשלוח את הטוקן בכותרת `X-GSM-Token`. מסך "אינטגרציות" מציג דוגמת Power Query מוכנה.
  - **קישור היומן (iCal) חייב להכיל את הטוקן בכתובת,** כי מנויי יומן לא יכולים לשלוח כותרות.

### פרטיות: אין פניות לשרתים חיצוניים
בעמודי המערכת הדפדפן לא פונה לאף שרת חיצוני:
- **הפונט Rubik** מגיע עם התוסף ועם ערכת העיצוב (רישיון SIL OFL 1.1, הקובץ `assets/fonts/OFL.txt`).
- **תמונות ה-emoji של WordPress** (מ-`s.w.org`) כבויות בעמודי המערכת.

בדיקת ה-E2E נכשלת על כל בקשה למארח חיצוני.

### צ׳אט (Botwa)
1. ב"הגדרות אתר", בכרטיס **"צ׳אט (Botwa)"**, מדביקים את קוד ההטמעה של הספק (`<script src="https://…/widget.js" data-key="wc_…">`).
2. בוחרים איפה להציג (עמוד ה-QR, המערכת, שאר עמודי האתר), מסמנים "הצ׳אט פעיל" ושומרים.

הקוד של הספק נשמר בתוך התוסף (עותק שנבדק), והצ׳אט נטען רק בלחיצה. פירוט ב-[`docs/plan-2.14.md`](docs/plan-2.14.md).

### דיווח על בעיית אבטחה
לא לפתוח issue ציבורי. יש לפנות ישירות למנהל המערכת ביחידת הבטיחות.

---

## פיתוח ובדיקות

### דרישות
PHP 8.2+ עם `pdo_sqlite`, Composer 2, Node.js 20+.

> ⚠️ **PHP 8.1 הגיעה לסוף חייה ב-31.12.2025, ו-8.2 תקבל עדכוני אבטחה רק עד 31.12.2026** (php.net). מומלץ לתכנן שדרוג של השרת ל-8.3 ומעלה.

```bash
composer install
```
```bash
npm ci
```
```bash
npx playwright install chromium
```

### בדיקות מהירות (בלי WordPress)
```bash
composer lint:php
```
```bash
npm run lint:js
```
```bash
composer test:unit
```
```bash
vendor/bin/phpcs
```
```bash
npm run test:dashboard
```

- `vendor/bin/phpcs` מריץ את ה-ruleset שב-[`phpcs.xml.dist`](phpcs.xml.dist): חוקי אבטחה של WordPress ותאימות ל-PHP 8.2+. **כל הפרה מכשילה את ה-CI.**
- סגנון WordPress המלא מדווח בנפרד (`composer phpcs:style`), לידיעה בלבד.

### בדיקת End-to-End מול WordPress אמיתי
הסקריפט מקים אתר WordPress זמני על SQLite, בלי MySQL, עם התוסף וערכת העיצוב מהריפו (symlink), ומפעיל שרת PHP מקומי:
```bash
WP_TEST_PASS='local-test-password' scripts/setup-test-wp.sh
```
```bash
BASE=http://127.0.0.1:8899 USER_NAME=admin PASS='local-test-password' WP_PATH=.wp-test/site npm run test:e2e
```

הבדיקה עוברת על:
- התחברות, וכל 25 המסכים.
- כל הכפתורים.
- צרופות: העלאה, הורדה, מחיקה מהדיסק, ודחיית קובץ מזויף.
- ייצוא, הדפסת QR ומדבקות.
- נקודות שחזור וביטול מחיקה.
- עמוד ה-QR ודיווח תקלה.
- לוח המצב הציבורי.

היא נכשלת על כל הפרת CSP או שגיאת JS. כדי לבדוק גרסת WordPress מסוימת: `WP_VERSION=6.4.5`.
האתר הזמני נוצר ב-`.wp-test/` (ב-`.gitignore`) ונועד לבדיקות בלבד.

### CI
[`.github/workflows/ci.yml`](.github/workflows/ci.yml) רץ על כל push ו-pull request:

| Job | מה נבדק | חוסם? |
|---|---|---|
| Lint | תחביר PHP ב-8.2 / 8.3 / 8.4 / 8.5, תחביר JS, התאמת מספרי גרסה | כן |
| PHPCS | אבטחה ותאימות PHP 8.2+ | כן |
| PHPCS style | סגנון WordPress מלא | לא (לידיעה) |
| Unit | טוקנים, rate limit, הגנת CSV, קריאת CSV ו-XLSX, ופענוח קודי QR | כן |
| Integration | מדיניות שמירה (19), תיקוני סקירת הקוד (24), בקשות פרטיות (18), משיכה מהאתר הישן מול שרת מדומה (52) והסרה מלאה של התוסף (7) על WordPress אמיתי | כן |
| E2E | WordPress 6.4.5 (המינימום) ו-latest | כן |
| נגישות | axe-core (WCAG 2.1 A/AA) על 35 תצוגות, ובדיקות מקלדת ופוקוס | כן |
| Build ZIPs | קובצי התקנה כ-artifact | — |

ה-Actions נעולים ל-SHA של גרסה מדויקת, כהגנה מפני שינוי זדוני בגרסה של צד שלישי. [Dependabot](.github/dependabot.yml) פותח פעם בחודש pull request לעדכון, וה-CI רץ עליו לפני מיזוג ידני.

---

## מבנה הריפו
```
plugins/gas-safety-manager/   התוסף (קוד שנשלח ללקוח)
themes/gas-safety-theme/      ערכת העיצוב
tests/                        בדיקות יחידה, לוח מצב ציבורי ו-E2E
scripts/                      הקמת אתר בדיקה, בניית ZIP, בדיקת JS
docs/                         מסמכי אבטחה
.github/workflows/ci.yml      CI
```

קובצי `composer.json`, `package.json` ו-`phpcs.xml.dist` הם כלי פיתוח בלבד. הם לא נכללים בקובצי ה-ZIP.

## רישיון
קנייני. יחידת הבטיחות, הטכניון.
