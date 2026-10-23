=== WP GitHub Updater ===
Contributors: sfambach
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPL-2.0-or-later

Updates plugins hosted in public GitHub repositories from their latest release.

== Description ==

AI disclosure: This plugin was built with AI assistance. The code was written largely by Claude (Anthropic) via Claude Code; Stefan Fambach specified, reviewed and tested it.

Every installed plugin with a `GitHub Plugin URI: owner/repo` header is checked against the latest GitHub release (cached for 6 hours, "Check again" on the Updates screen checks fresh). Updates appear on the normal WordPress Updates screen, and WordPress's own "Enable auto-updates" link works for them too.

For AI connectors (MCP) two abilities are registered:

* `github-updater/status` – installed and latest version of each GitHub plugin.
* `github-updater/update` – installs the latest release of one plugin or of all plugins with an update.

Only public repositories are supported; no token is needed.

== Changelog ==

= 1.1.2 =
* Finds the GitHub header even when another plugin listed the installed plugins before this one loaded; until now the Updates screen then showed no GitHub updates at all.
* A failed check (GitHub rate limit, timeout) keeps the release already known instead of hiding it for 30 minutes.
* Ability github-updater/status reports `offered`: the version the Updates screen currently shows.

= 1.1.1 =
* New releases show up within an hour instead of only after core's 12-hour check or a manual update.

= 1.1.0 =
* Installs the ZIP attached to a release when there is one (built plugins with vendor/ or build/), and the source archive only when there is none.

= 1.0.1 =
* Falls back to the github.com release redirect when the API refuses (anonymous rate limit on shared hosting).

= 1.0.0 =
* First release.
