=== WP GitHub Updater ===
Contributors: sfambach
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Updates plugins hosted in public GitHub repositories from their latest release.

== Description ==

Every installed plugin with a `GitHub Plugin URI: owner/repo` header is checked against the latest GitHub release (cached for 6 hours, "Check again" on the Updates screen checks fresh). Updates appear on the normal WordPress Updates screen, and WordPress's own "Enable auto-updates" link works for them too.

For AI connectors (MCP) two abilities are registered:

* `github-updater/status` – installed and latest version of each GitHub plugin.
* `github-updater/update` – installs the latest release of one plugin or of all plugins with an update.

Only public repositories are supported; no token is needed.

== Changelog ==

= 1.0.0 =
* First release.
