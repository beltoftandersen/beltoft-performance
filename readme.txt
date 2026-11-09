=== Beltoft Performance ===
Contributors: beltoft
Tags: performance, woocommerce, plugins, scripts, speed
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Loads only the plugins and files each page type uses. Rules are proposed by a check and approved by you.

== Description ==

A check watches which plugins and files every page type actually needs and proposes rules. Each rule is tested and you approve it. Once approved, a small must-use loader removes those plugins for that page type before WordPress loads them, and unused scripts and styles are dropped from the page.

Nothing is switched off without a rule you accepted. With any doubt, the loader changes nothing.

== Changelog ==

= 0.1.1 =
* Plugin Check clean-up.
