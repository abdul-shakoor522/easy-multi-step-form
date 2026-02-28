# 🚀 Easy Multi Step Form

[![WordPress Version](https://img.shields.io/badge/WordPress-5.0+-blue.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPL%20v2+-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A lightweight, secure, and highly customizable WordPress plugin for creating professional multi-step contact forms. Designed for seamless user experience and effortless administration.

---

## ✨ Key Features

- **🎯 Multi-Step Workflow**: Break long forms into manageable steps to increase conversion rates.
- **⚡ AJAX Powered**: Seamless form submissions without page reloads.
- **🎨 Custom Styling**: Easily customize colors, fonts, margins, and borders directly from the settings.
- **🛡️ Rock-Solid Security**: Built with industry-standard security practices (Nonces, Sanitization, Escaping).
- **📧 Smart Notifications**: Automatic email alerts for admins and confirmation emails for users.
- **📊 Submission Dashboard**: A dedicated admin panel to view and manage all form entries.
- **📱 Fully Responsive**: Works perfectly on mobile, tablet, and desktop devices.
- **🌐 Accessible Design**: Follows ARIA standards for better accessibility.
- **🛠️ Advanced Fields**: Supports Datepickers, custom Select menus, and reCAPTCHA (v2/v3).

---

## 🚀 Installation

1. **Download & Upload**: Upload the plugin folder to your `/wp-content/plugins/` directory.
2. **Activate**: Go to the 'Plugins' menu in your WordPress dashboard and click 'Activate' for **Easy Multi Step Form**.
3. **Display**: Paste the shortcode `[easy_multi_step_form]` on any page or post where you want the form to appear.

---

## 🛠️ Usage

### Displaying the Form
Simply use the following shortcode:
```text
[easy_multi_step_form]
```

### Managing Submissions
Navigate to the **Contact Submissions** menu in your WordPress back-end to view, filter, and manage all received form entries.

---

## 🛡️ Security

This plugin respects the highest WordPress security standards:
- **Nonce Verification**: Protects against CSRF attacks.
- **Data Sanitization**: All user inputs are cleaned using `sanitize_text_field()` and `wp_kses_post()`.
- **Output Escaping**: All data displayed is properly escaped with `esc_html()` and `esc_attr()`.
- **Bot Protection**: Seamless integration with Google reCAPTCHA.

---

## 💻 Tech Stack

- **Core**: PHP & WordPress API
- **Frontend**: JavaScript (ES6+), CSS3
- **Libraries**: 
  - [Flatpickr](https://flatpickr.js.org/) for modern date selection.
  - [Choices.js](https://choices-js.github.io/Choices/) for beautiful select menus.

---

## � Changelog

### 1.1.0 (Current)
- Added Action Scheduler support for background email processing.
- Optimized form submission performance.
- Enhanced styling options in settings.

### 1.0.0
- Initial release.

---

## �📄 License

This project is licensed under the GPLv2 or later.

---

**Developed with ❤️ by [shakoor](https://shakoor-wpdev.vercel.app)**
