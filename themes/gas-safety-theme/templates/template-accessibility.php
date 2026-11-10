<?php
/**
 * Template Name: הצהרת נגישות
 *
 * Accessibility statement page (public). Assign it to a Page titled
 * "הצהרת נגישות" and link it from the site footer. The content targets
 * WCAG 2.1 AA and the Israeli standard SI 5568, and is marked as a DRAFT
 * that requires review by a certified accessibility consultant before it
 * is published as an official statement.
 *
 * Replace every [[ ... ]] placeholder with real organizational details.
 *
 * @package GasSafetyTheme
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$updated = get_the_modified_date( 'd/m/Y' );
?>
<main id="gs-legal" class="gs-legal" role="main" dir="rtl" lang="he">
	<div class="gs-legal__wrap">

		<p class="gs-legal__draft" role="note">
			<strong>טיוטה טכנית.</strong> מסמך זה הוכן כבסיס ראשוני ומחייב בדיקה ואישור של מורשה נגישות
			ויועץ משפטי לפני פרסום כהצהרת נגישות רשמית. יש להשלים את הפרטים המסומנים בסוגריים מרובעים.
		</p>

		<h1>הצהרת נגישות</h1>
		<p class="gs-legal__meta">עודכן לאחרונה: <?php echo esc_html( $updated ); ?></p>

		<section>
			<h2>מחויבות לנגישות</h2>
			<p>
				[[שם הארגון / יחידת הבטיחות]] רואה חשיבות רבה במתן שירות שוויוני ונגיש לכלל המשתמשים,
				לרבות אנשים עם מוגבלות. אנו פועלים להנגשת מערכת ניהול תחזוקת הגזים ומשקיעים מאמץ מתמשך
				לשיפור הנגישות של המערכת והתכנים בה.
			</p>
		</section>

		<section>
			<h2>רמת הנגישות ותקנים</h2>
			<p>
				המערכת נבנתה במטרה לעמוד בהנחיות <strong>WCAG 2.1 ברמה AA</strong> ובדרישות
				<strong>תקן ישראלי ת״י 5568</strong>, בהתאם לתקנות שוויון זכויות לאנשים עם מוגבלות
				(התאמות נגישות לשירות). ההתאמות נבדקו בבדיקה ידנית ואוטומטית; עמידה מלאה טעונה
				אישור של מורשה נגישות.
			</p>
		</section>

		<section>
			<h2>התאמות הנגישות שבוצעו</h2>
			<ul>
				<li>תמיכה מלאה בעברית ובכיווניות RTL.</li>
				<li>מבנה HTML סמנטי והיררכיית כותרות תקינה.</li>
				<li>ניווט מלא באמצעות מקלדת וסימון פוקוס נראה לעין.</li>
				<li>תוויות (labels) והודעות שגיאה ברורות בטפסים.</li>
				<li>ניגודיות צבעים מספקת וטקסט חלופי לתמונות משמעותיות.</li>
				<li>שמות נגישים לכפתורים, אייקונים ורכיבי ממשק, ותמיכה בקוראי מסך.</li>
				<li>אפשרות הגדלת תצוגה (זום) של 200% לפחות ללא פגיעה מהותית בתפקוד.</li>
				<li>הימנעות מהסתמכות על צבע בלבד להעברת משמעות.</li>
			</ul>
		</section>

		<section>
			<h2>מגבלות נגישות ידועות</h2>
			<p>
				חלק מהתכנים או הרכיבים עשויים שלא להיות נגישים במלואם. אנו פועלים לתיקון מתמשך.
				[[פרטו כאן מגבלות ידועות, אם קיימות — למשל קבצים שהועלו על ידי משתמשים, רכיבי צד שלישי וכד׳]].
			</p>
		</section>

		<section>
			<h2>פנייה בנושא נגישות ורכז הנגישות</h2>
			<p>
				נתקלתם בבעיית נגישות, או שיש לכם הצעה לשיפור? נשמח לקבל את פנייתכם:
			</p>
			<ul>
				<li>רכז/ת נגישות: [[שם מלא]]</li>
				<li>דוא״ל: [[כתובת דוא״ל]]</li>
				<li>טלפון: [[מספר טלפון]]</li>
				<li>כתובת: [[כתובת למשלוח דואר]]</li>
			</ul>
			<p>
				נטפל בפנייתכם בהקדם וניצור קשר בתוך [[מספר ימי עסקים, למשל 14 ימי עסקים]] ממועד קבלתה.
			</p>
		</section>

	</div>
</main>
<?php
get_footer();
