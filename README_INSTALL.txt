EVENT EVALUATION AND FEEDBACK MANAGEMENT SYSTEM
PHP 8+ / MySQL / Responsive CSS / JavaScript

DEFAULT ADMIN ACCOUNT
Username: admin
Password: admin123
IMPORTANT: Log in and change this password immediately under Admin > Settings.

INFINITYFREE / CPANEL INSTALLATION
1. Create a MySQL database in your hosting control panel.
2. Open phpMyAdmin for that database.
3. Import schema.sql into the database you created.
   Do not create another database from phpMyAdmin. schema.sql creates tables only.
4. Edit includes/config.php and replace:
      DB_HOST
      DB_NAME
      DB_USER
      DB_PASS
   with the exact values from your hosting control panel.
5. BASE_URL can normally remain blank. If QR links are incorrect, set it to your full site URL, for example:
      define('BASE_URL', 'https://example.infinityfreeapp.com');
6. Upload ALL project files and folders into htdocs/ (or public_html/ on cPanel).
7. Open your website in a browser.
8. Go to /login.php and sign in with admin / admin123.
9. Open Settings and change the administrator password and organization name.
10. Create an event. You can automatically load the standard 27-item Likert template plus 5 open-ended questions.

IMPORTANT NOTES
- Chart.js and QRCode.js are loaded from public CDNs. Charts/QR rendering therefore require an internet connection in the browser.
- Core evaluation submission, event management, scoring, reports, and CSV export do not require Composer, Node.js, Laravel, or command-line access.
- Do not expose includes/config.php publicly. The supplied includes/.htaccess blocks direct web access on Apache-compatible hosting.
- For security, use HTTPS if your host provides it.

MAIN PAGES
Public:
  index.php             Open evaluations landing page
  evaluate.php          Participant evaluation form
  submit-evaluation.php Saves responses
  thank-you.php         Confirmation page
  login.php             Administrator login

Admin:
  admin/dashboard.php   Dashboard statistics and charts
  admin/events.php      Event management
  admin/event-create.php Create event
  admin/event-edit.php  Edit event/status
  admin/forms.php       Evaluation form list
  admin/questions.php   Question/category builder
  admin/responses.php   Response viewer and CSV export
  admin/analytics.php   Statistical analysis and charts
  admin/reports.php     Printable report and CSV summary
  admin/qr.php          QR code/public evaluation link
  admin/settings.php    Site identity and admin password

QUESTION UPLOAD UPDATE
----------------------
This edition uses a simplified question workflow:
Create Event -> Upload Questions -> Review -> Open Evaluation -> Share QR/Link -> Analyze Responses.

The admin can upload .txt (one question per line) or .csv files. A ready-made question_upload_template.csv is included in the project root.

GOOGLE FORMS + GOOGLE DRIVE (OPTIONAL)
--------------------------------------
This edition includes optional Google Forms generation. After the normal system
is installed, follow GOOGLE_FORMS_SETUP.txt. The admin can then open
Admin > Google Forms and create a Google Form from any event's uploaded
questionnaire. A linked Google Sheets response file is created automatically in
Google Drive.

Important distinction:
- Website evaluation responses -> MySQL database in this system.
- Generated Google Form responses -> linked Google Sheets file in Google Drive.

The Google Form and Sheet URLs are saved with the event for quick admin access.

EDITABLE PARTICIPANT INFORMATION
--------------------------------
Each event now has its own Participant Information field builder.
Admin flow:
1. Create Event.
2. Configure Participant Information.
3. Add, edit, remove, restore, or reorder fields.
4. Upload evaluation questions.
5. Preview and open the evaluation.

Supported participant field types:
- Short Text
- Email
- Number
- Dropdown
- Single Choice
- Multiple Choice
- Paragraph

Removing a field after responses exist hides it from future submissions but preserves historical answers. Existing installations are automatically upgraded when the application loads. For manual migration, import migration_participant_fields.sql.

Google Forms generation also uses the active Participant Information fields. After changing participant fields, regenerate the Google Form so the Google copy reflects the latest layout.
