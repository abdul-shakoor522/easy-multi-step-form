A secure contact form plugin that saves submissions and sends admin email notifications.

== Description ==

Easy Multi Step Form is a lightweight, secure WordPress plugin that allows you to add a contact form to your website. All form submissions are:

* **Securely saved** to the database with proper sanitization
* **Email notifications** sent to site admin
* **Confirmation emails** sent to users
* **AJAX-powered** for seamless form submission
* **Fully accessible** with ARIA labels and semantic HTML

Features:
- Simple shortcode: `[easy_multi_step_form]`
- Admin panel to view and manage submissions
- Built-in security with nonce verification and input sanitization
- Responsive design
- Multiple email notifications
- Efficient database storage with indexing

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/easy-multi-step-form`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Use the shortcode `[easy_multi_step_form]` on any page or post
4. Navigate to "Contact Submissions" in the admin menu to view submissions

== Usage ==

Simply add this shortcode to any page or post:

`[easy_multi_step_form]`

== Security ==

This plugin follows WordPress security best practices:
- All user input is sanitized with `sanitize_text_field()`, `sanitize_email()`, `wp_kses_post()`
- All output is escaped with `esc_html()`, `esc_attr()`, `esc_url()`
- Nonce verification on all form submissions
- Prepared SQL statements with `$wpdb->prepare()`
- Capability checks for admin functionality

== Changelog ==

= 1.0.0 =
* Initial release

== License ==

This plugin is licensed under GPLv2 or later. See LICENSE file for details.