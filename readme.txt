=== Connect Gravity Forms with Google Sheets ===
Contributors: mervanagency
Tags: gravity forms, google sheets, form integration, spreadsheet, automation
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically send Gravity Forms submissions to Google Sheets. Map form fields to sheet columns, set conditions, and manage multiple feeds per form.

== Description ==

Connect Gravity Forms with Google Sheets is a powerful integration plugin that automatically sends your Gravity Forms entries to Google Sheets spreadsheets — without any third-party automation tools or subscriptions.

**Key Features:**

* Connect multiple Google accounts via secure OAuth 2.0
* Map any form field to any sheet column with a visual field mapper
* Choose from multiple trigger events (form submit, payment events, entry update)
* Conditional logic support — only send entries that match your rules
* Support for all Gravity Forms field types (see full list below)
* Manually resend any entry to Google Sheets from the entry detail page
* Multiple independent feeds per form, each pointing to a different spreadsheet or sheet
* Entry notes logged automatically for every success and error
* Token auto-refresh — connections stay alive without manual intervention

**Supported Field Types:**

* Text, Email, Phone, Number, Website
* Textarea
* Select, Radio, Checkbox
* Multi-select, Multi-choice
* Name (with sub-field support: prefix, first, middle, last, suffix)
* Address (street, city, state, zip, country)
* Date (with custom output format), Time
* File Upload (single and multiple)
* List (single-column and multi-column)
* Product, Quantity, Price
* Image Choice
* All entry meta: Entry ID, Date Created, Source URL, User IP, Created By, Payment Status

**Trigger Events:**

* Form Submitted
* Submission Completed (after all notifications are sent)
* Payment Completed
* Payment Refunded
* Payment Fulfilled
* Entry Updated

**Custom Field Templates:**

Advanced users can write custom template strings using a flexible tag syntax:

* `{28}` — Full formatted value of field 28
* `{28.3}` — Sub-input 3 of field 28 (e.g. product quantity)
* `{28:label}` — Selected choice label(s)
* `{28:first}` — First name sub-field of a Name field
* `{28:img_url}` — Image URL(s) from an Image Choice field

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Forms → Settings → Google Sheets**
4. Follow the setup guide to connect your Google account
5. Open any form and go to **Settings → Google Sheets** to create feeds

== Setting Up Google OAuth ==

Before connecting your Google account, you need to create OAuth credentials in Google Cloud Console.

**Step 1 — Create a Google Cloud Project**

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Click **Select a project → New Project**
3. Enter a project name and click **Create**

**Step 2 — Enable Required APIs**

1. Go to **APIs & Services → Library**
2. Search for **Google Sheets API** and click **Enable**
3. Search for **Google Drive API** and click **Enable**

**Step 3 — Configure OAuth Consent Screen**

1. Go to **APIs & Services → OAuth consent screen**
2. Select **External** as the user type
3. Fill in the required fields (App name, support email)
4. Under **Test Users**, add your Google account email
5. Save and continue through all steps

**Step 4 — Create OAuth 2.0 Credentials**

1. Go to **APIs & Services → Credentials**
2. Click **Create Credentials → OAuth 2.0 Client ID**
3. Select **Web Application** as the application type
4. Under **Authorized Redirect URIs**, add your redirect URI
   (copy this from the plugin settings page — **Forms → Settings → Google Sheets**)
5. Click **Create** and note your **Client ID** and **Client Secret**

**Step 5 — Connect Your Account**

1. Go to **Forms → Settings → Google Sheets → Add New Account**
2. Give the account a name (optional)
3. Enter your Client ID and Client Secret
4. Click **Save & Connect with Google**
5. Complete the Google authorization prompt
6. You will be redirected back to the settings page with a success message

== Creating a Feed ==

1. Open any Gravity Form
2. Go to **Settings → Google Sheets**
3. Click **Add New Feed**
4. Select your Google Account
5. Select the Spreadsheet, then the Sheet (tab)
6. Map your form fields to sheet column headers
7. (Optional) Select a trigger event — defaults to **Form Submission**
8. (Optional) Add conditional logic rules
9. Click **Save Feed**

== Frequently Asked Questions ==

= Does this plugin require Gravity Forms? =

Yes. Gravity Forms must be installed and activated. Version 2.6 or higher is required.

= Can I connect multiple Google accounts? =

Yes. You can connect any number of Google accounts. Each feed independently selects which account to use, so you can send data to spreadsheets owned by different Google users.

= Can I send one form to multiple spreadsheets? =

Yes. Create multiple feeds for the same form. Each feed can target a different spreadsheet, sheet tab, trigger event, or set of conditions.

= What happens if a Google API call fails? =

The error is recorded in the entry notes (visible in the entry detail view in Gravity Forms), so you can see exactly what went wrong. You can then manually resend the entry from the same page.

= Can I manually send an entry to Google Sheets? =

Yes. Open any entry in Gravity Forms — you will see a **Google Sheets** panel in the right-hand sidebar. Click **Send to Google Sheets** to push that entry to all active feeds, or choose a specific feed.

= Does it support conditional logic? =

Yes. Each feed has its own conditional logic panel. You can choose to send entries only when specific field values match your defined rules.

= What field types are supported? =

All standard Gravity Forms field types are supported, including text, email, phone, address, name, checkbox, radio, select, file upload, list, product, image choice, and all entry meta fields such as entry ID, submission date, and user IP.

= Will it work on localhost? =

Google OAuth requires a publicly accessible redirect URI. For local development, use a tunneling tool such as ngrok or ddev share to expose a public URL, then register that URL in your Google Cloud Console credentials.

= Is my data secure? =

Yes. OAuth tokens are stored in your WordPress database and are never transmitted to any third-party server. All API requests are made directly from your WordPress site to Google's APIs. Your form data never passes through the plugin author's infrastructure.

= Does it work with payment add-ons? =

Yes. The plugin listens for Gravity Forms payment events (completed, refunded, fulfilled) emitted by official Gravity Forms payment add-ons such as Stripe, PayPal, and Square.

= How do I update the spreadsheet when an entry is edited? =

Create a feed with the trigger set to **Entry Updated**. Whenever an admin edits an entry in the Gravity Forms entry viewer, a new row will be appended to the sheet with the updated values.

== Screenshots ==

1. Plugin settings page — manage connected Google accounts
2. Add new account — enter Google Cloud OAuth credentials
3. Feed list — view all feeds configured for a form
4. Feed editor — visually map form fields to sheet columns
5. Conditional logic — control exactly when entries are sent
6. Entry detail sidebar — manually resend a single entry to Google Sheets

== Changelog ==

= 1.0.0 =
* Initial release
* Connect multiple Google accounts via OAuth 2.0
* Full visual feed editor with field mapping
* Support for all Gravity Forms field types including composite and sub-input fields
* Custom template tag syntax for advanced field composition
* Conditional logic per feed
* Multiple trigger events: form submit, payment events, entry updated
* Manual send from entry detail sidebar
* Entry notes for success and error logging
* Token auto-refresh — connections stay active without user intervention

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade steps required.

== Privacy Policy ==

This plugin connects to Google's APIs to send form data to Google Sheets.

* Form data is transmitted directly from your WordPress site to Google's servers over HTTPS
* No data is routed through or stored on the plugin author's servers
* OAuth access tokens and refresh tokens are stored in your WordPress database
* Google's privacy policy governs data received by Google Sheets: https://policies.google.com/privacy
* Users whose form data is collected should be informed in your site's own privacy policy that submissions may be sent to Google Sheets

== Requirements ==

* WordPress 5.8 or higher
* PHP 7.4 or higher
* Gravity Forms 2.6 or higher
* A Google account with access to Google Cloud Console