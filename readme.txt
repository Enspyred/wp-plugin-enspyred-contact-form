=== Enspyred Contact Form ===
Contributors: enspyred
Tags: contact form, react, recaptcha, spam protection, email
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.3.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern, React-based contact form plugin with Google reCAPTCHA v3 integration and advanced spam protection.

== Description ==

Enspyred Contact Form is a powerful, user-friendly contact form plugin built with React 19 and modern web technologies. It provides a seamless experience for both site administrators and visitors.

= Key Features =

* **Modern React Interface** - Built with React 19 for a smooth, responsive user experience
* **Spam Protection** - Google reCAPTCHA v3 integration for intelligent spam filtering
* **International Support** - International phone number input with country selection
* **Customizable** - Easy configuration through WordPress admin panel
* **Multiple Forms** - Create and manage multiple contact forms
* **Email Notifications** - Automatic email notifications for form submissions
* **Shortcode Integration** - Easy placement with WordPress shortcodes
* **REST API** - Secure form submission via WordPress REST API
* **Styled Components** - Professional, customizable styling

= How It Works =

1. Install and activate the plugin
2. Configure your contact form settings in the WordPress admin
3. Add your Google reCAPTCHA v3 keys
4. Place the contact form using the shortcode `[enspyred_contact_form]`
5. Receive email notifications when visitors submit forms

= Privacy & Security =

This plugin uses Google reCAPTCHA v3 to protect against spam. By using this plugin, you acknowledge that form submissions will be processed by Google's reCAPTCHA service. Please review Google's Privacy Policy and Terms of Service.

Form data is processed securely through the WordPress REST API and can be configured to send email notifications to site administrators.

== Installation ==

= IMPORTANT: Download the Correct ZIP File =

**DO NOT** download the repository ZIP from the main branch! The repository includes development files (node_modules, source code, etc.) totaling ~129MB and will not work properly when installed in WordPress.

**ALWAYS** download the official release ZIP from GitHub Releases. These are clean, production-ready distributions (~2-3MB) that contain only the necessary files.

= Installation Steps =

1. Download the latest **release ZIP** from GitHub: https://github.com/enspyred/wp-plugin-enspyred-contact-form/releases
2. Log in to your WordPress admin panel
3. Navigate to Plugins > Add New > Upload Plugin
4. Choose the downloaded release ZIP file and click "Install Now"
5. Activate the plugin
6. Follow the configuration steps below

= Configuration =

1. Navigate to **Contact Forms** in your WordPress admin menu
2. Click **Settings** to configure global settings
3. Add your Google reCAPTCHA v3 Site Key and Secret Key
4. Configure email notification settings
5. Create your first contact form
6. Use the shortcode `[enspyred_contact_form]` to display the form on any page or post

== Frequently Asked Questions ==

= Do I need a Google reCAPTCHA account? =

Yes, you'll need to register for Google reCAPTCHA v3 keys at https://www.google.com/recaptcha/admin. It's free and helps protect your forms from spam.

= Can I customize the form fields? =

Currently, the plugin includes standard contact form fields (name, email, phone, country, message). Additional customization options are planned for future releases.

= Can I create multiple contact forms? =

Yes! You can create and manage multiple contact forms with different configurations through the WordPress admin panel.

= How do I display the contact form? =

Use the shortcode `[enspyred_contact_form]` in any page, post, or widget area. You can also specify a form ID if you have multiple forms: `[enspyred_contact_form id="form-id"]`

= Where do form submissions go? =

Form submissions trigger email notifications that are sent to the email addresses configured in your plugin settings. You can configure multiple recipient addresses.

= Is the plugin GDPR compliant? =

The plugin processes form data according to your configuration. You should add appropriate privacy notices to your site regarding data collection and the use of Google reCAPTCHA. The plugin does not store form submissions by default.

= What PHP version is required? =

PHP 8.0 or higher is required for optimal performance and security.

== Screenshots ==

1. Contact form on the frontend
2. Plugin settings page
3. Contact forms management
4. Form configuration options

== Changelog ==

= 1.0.3 =
* Fixed release workflow for standalone plugin distribution
* Added dynamic version tracking in form component
* Improved build and packaging process

= 1.0.2 =
* Updates and improvements

= 1.0.1 =
* Bug fixes and optimizations

= 1.0.0 =
* Initial release
* React 19-based contact form
* Google reCAPTCHA v3 integration
* International phone number support
* Country selection
* Email notifications
* WordPress REST API integration
* Shortcode support
* Multiple form management
* Admin configuration panel

== Upgrade Notice ==

= 1.0.0 =
Initial release of Enspyred Contact Form.

== Additional Information ==

= Download & Updates =

Download the latest version from GitHub: https://github.com/enspyred/wp-plugin-enspyred-contact-form/releases

The plugin includes automatic update notifications - you'll be notified in your WordPress admin when new versions are available.

= Support =

For support, feature requests, or bug reports, please visit our GitHub repository or contact us through our website.

= Credits =

* Built with React 19
* Powered by Vite
* Uses Styled Components for styling
* Integrates Google reCAPTCHA v3
* Built by Enspyred

= License =

This plugin is licensed under the GPLv2 or later.
