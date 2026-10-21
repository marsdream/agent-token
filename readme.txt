=== Agent Token — API Access for AI Agents ===
Contributors: marsdream
Tags: rest-api, api, token, authentication, ai
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://ko-fi.com/aitoken

Give your AI agent a single, revocable token for the WordPress REST API — no username and password pairs, no Basic Auth.

== Description ==

Your AI assistant needs to work in WordPress: draft posts, review content, update pages. To do that through the REST API, it needs credentials.

The built-in option, Application Passwords, was designed for humans pasting a username and password pair into an app once. AI agents don't work that way:

* **Agent credential vaults store one secret, not two.** Most agent platforms (including Muse's custom connectors) have a single API-key field. An Application Password is a username *plus* a password — it simply does not fit, and the connection gets refused.
* **Application Passwords rely on Basic Auth.** Many shared and managed hosts strip or block the Authorization header, so perfectly valid credentials fail with no useful error.
* **One leaked password pair is hard to scope.** You end up handing an agent credentials tied to a real user account, and revoking means digging through profile pages.

**Agent Token takes a different approach: one token, built for agents.**

* Generate a token in Settings → Agent Token. It is shown **once**; only its SHA-256 hash is stored.
* Map the token to a dedicated WordPress user you choose (for example, an Editor account created just for your agent). The token can do exactly what that user can do — no more.
* Your agent sends the token in a simple header (`X-Agent-Token`) over HTTPS. No Basic Auth, no username, nothing else to configure.
* Rotate or revoke the token in one click whenever you want — the old token stops working instantly.
* **Agent self-discovery:** the plugin publishes a small public manifest (`/wp-json/agent-token/v1/manifest`) describing how to connect — header name, site URL, and the mapped account's capabilities. Point your agent at your site and it can figure out the rest.
* **Copy-paste setup:** when you generate a token, the plugin gives you a ready-made setup snippet. Paste it to your agent and it is connected.

Security by design: HTTPS only, hashed storage, constant-time comparison, no telemetry, no external calls, and nothing is authenticated when no token is configured. The plugin adds no public write endpoints of its own — it only authenticates standard REST API requests as the user you mapped.

Born in production: Agent Token was written because an AI assistant reviewing drafts on a real site could not use Application Passwords at all. It has run that workflow daily since.

Note: if your tooling can use Application Passwords (local scripts, your own server code), they remain a fine built-in option. Agent Token exists for the growing number of agent platforms that cannot.

== Installation ==

1. Upload the `agent-token` folder to `/wp-content/plugins/`, or install it via the WordPress plugins screen.
2. Activate the plugin.
3. (Recommended) Create a dedicated user for your agent, with the smallest role it needs (Editor is typical), under Users → Add New.
4. Go to Settings → Agent Token, pick the mapped user, and click "Generate token".
5. Copy the token and the setup snippet immediately — the token is shown only once — and paste them into your agent's credential vault / connector settings.

== Frequently Asked Questions ==

= Is this secure? =
The token is a 48-character random string (about 285 bits of entropy). Only its SHA-256 hash is stored in your database, comparison is constant-time, and tokens are only honoured over HTTPS. A database leak does not leak usable tokens. Revocation is instant and per-token.

= How is this different from Application Passwords? =
Application Passwords are a username + password pair authenticated via Basic Auth. Agent Token is a single secret sent as a header, designed to fit agent platforms' single-key credential stores and to avoid hosts that block Basic Auth. See the Description for the full comparison.

= Which agents does it work with? =
Any agent or tool that can call the REST API with a custom header: Muse custom connectors, MCP-based tools, local automation scripts, and similar. The public manifest endpoint lets conforming agents discover the connection details automatically.

= What can an agent do with a token? =
Exactly what the mapped WordPress user can do, via the standard REST API. Map the token to a low-privilege dedicated account to keep the blast radius small. The plugin itself grants no extra permissions.

= What happens if I deactivate or uninstall the plugin? =
All tokens stop working immediately. Uninstalling removes the plugin's settings from the database.

= Does it work on multisite? =
Not yet. Version 1.0 targets single-site installs.

= Does the plugin phone home or collect data? =
No. There are no external requests, no analytics, and no telemetry of any kind.

== Screenshots ==

1. Settings → Agent Token: generate a token, choose the mapped user, revoke in one click.
2. A freshly generated token with its copy-paste agent setup snippet (shown once).
3. The public agent discovery manifest, as seen by an agent connecting for the first time.

== Changelog ==

= 1.0.0 =
* Initial release.
* Single revocable token mapped to a chosen WordPress user.
* SHA-256 hashed storage, HTTPS-only header authentication.
* Public agent discovery manifest and copy-paste setup snippets.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
