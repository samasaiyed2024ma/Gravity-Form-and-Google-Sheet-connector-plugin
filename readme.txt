=== Connect Gravity Forms with Google Sheets ===
Contributors: Mervan Agency
Tags: gravity forms, google sheets, form integration, spreadsheet, automation
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0

Automatically send Gravity Forms submissions to Google Sheets. Map form fields to sheet columns, set conditions, and manage multiple feeds.

== Description ==

Connect Gravity Forms with Google Sheets is a powerful integration plugin that automatically sends your Gravity Forms submissions to Google Sheets spreadsheets.

**Key Features:**

* 🔗 Connect multiple Google accounts
* 📊 Map any form field to any sheet column
* ⚡ Multiple trigger events (form submit, payment, entry update)
* 🔀 Conditional logic support
* 📋 Support for all Gravity Forms field types
* 🔄 Manual send from entry detail page
* 🗂️ Multiple feeds per form
* 🔒 Secure OAuth 2.0 authentication

**Supported Field Types:**

* Text, Email, Phone, Number
* Textarea, Select, Radio, Checkbox
* Name, Address, Date, Time
* File Upload (single & multiple)
* List fields (single & multi-column)
* Product fields
* Multi-select
* All meta fields (Entry ID, Date, IP, etc.)

**Trigger Events:**

* Form Submitted
* Submission Completed (after notifications)
* Payment Completed
* Payment Refunded
* Payment Fulfilled
* Entry Updated

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
4. Add your Google email as a **Test User**
5. Save and continue

**Step 4 — Create OAuth 2.0 Credentials**

1. Go to **APIs & Services → Credentials**
2. Click **Create Credentials → OAuth 2.0 Client ID**
3. Select **Web Application** as the application type
4. Under **Authorized Redirect URIs**, add your redirect URI
   (You can copy this from the plugin settings page)
5. Click **Create**
6. Copy the **Client ID** and **Client Secret**

**Step 5 — Connect Your Account**

1. Go to **Forms → Settings → Google Sheets → Add New Account**
2. Enter your Client ID and Client Secret
3. Click **Save & Connect with Google**
4. Authorize the app in the Google popup

== Creating a Feed ==

1. Open any Gravity Form
2. Go to **Settings → Google Sheets**
3. Click **Add New Feed**
4. Select your Google Account
5. Select the Spreadsheet and Sheet
6. Map your form fields to sheet columns
7. (Optional) Set conditional logic
8. Click **Save Feed**

== Frequently Asked Questions ==

= Does this plugin require Gravity Forms? =

Yes, Gravity Forms must be installed and activated. Any version 2.6 or higher is supported.

= Can I connect multiple Google accounts? =

Yes, you can connect as many Google accounts as you need. Each feed can use a different account.

= Can I send one form to multiple spreadsheets? =

Yes, you can create multiple feeds per form, each sending to a different spreadsheet or sheet.

= What happens if the Google API call fails? =

The error is logged in the entry notes so you can see exactly what went wrong. You can then manually resend the entry from the entry detail page.

= Can I manually send an entry to Google Sheets? =

Yes, open any entry in Gravity Forms and you will see a **Google Sheets** panel in the sidebar with a **Send to Google Sheets** button.

= Does it support conditional logic? =

Yes, each feed supports conditional logic. You can choose to only send entries to Google Sheets when specific field conditions are met.

= Will it work on localhost? =

Google OAuth requires a publicly accessible URL. For local development, use a tunneling tool like ngrok or ddev share to get a public URL.

= What field types are supported? =

All standard Gravity Forms field types are supported including text, email, phone, address, name, checkbox, radio, select, file upload, list, product, and all entry meta fields.

= Is my data secure? =

Yes. OAuth tokens are stored securely in your WordPress database. We never store your data on external servers. All API calls are made directly from your WordPress site to Google's APIs.

== Screenshots ==

1. Plugin settings page — manage connected Google accounts
2. Add new account — enter Google Cloud credentials
3. Feed list — view all feeds for a form
4. Feed editor — map form fields to sheet columns
5. Conditional logic — send entries only when conditions are met
6. Entry detail — manually send entries to Google Sheets

== Changelog ==

= 1.0.0 =
* Initial release
* Connect multiple Google accounts via OAuth 2.0
* Create feeds with field mapping
* Support for all Gravity Forms field types
* Conditional logic support
* Multiple trigger events
* Manual send from entry detail page
* Entry notes for success and error logging

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Privacy Policy ==

This plugin connects to Google's APIs to send form data to Google Sheets. 

* Data is sent directly from your WordPress site to Google's servers
* No data passes through our servers
* OAuth tokens are stored in your WordPress database
* Google's privacy policy applies to data sent to Google Sheets: https://policies.google.com/privacy

== Requirements ==

* WordPress 5.8 or higher
* PHP 7.4 or higher  
* Gravity Forms 2.6 or higher
* A Google account with access to Google Cloud Console