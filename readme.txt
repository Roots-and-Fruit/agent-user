=== Agent Role ===
Contributors: webdevmattcrom
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: users, roles, rest-api

An Agent role for accounts that cannot log in with a password.

== Description ==

Agent Role adds a user role named Agent. An Agent can publish and upload like an Author. The account cannot sign in on the login screen, cannot reset its password, and cannot open wp-admin.

Create the account from Users, then Agents. The password is shown once, in a window, with copy buttons. Open an agent from the list to choose what that account can do, which abilities it may run, and the instructions sent when it connects. Revoke the password from the list when you want to replace it. Activity lists what those agents tried. A name links to that agent's screen. A deleted account is marked, with the deletion time when it was recorded.

The normal Add User screen does not offer the Agent role.

== Installation ==

1. Upload the `agent-role` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Users, then Agents.

== Frequently Asked Questions ==

= Can a person log in as an Agent? =

No. Password sign-in and password reset are refused. The application password works on the REST API only.

= Does this work with the WordPress MCP Adapter? =

Yes, and the adapter is optional. When it is active, the agent list can show the MCP endpoint and a client config. The password in that config is a placeholder, because WordPress does not keep the plaintext. A setting, off by default, limits the adapter's default server to Agent accounts. Each agent can also store instructions that the adapter sends when that account connects.

= What happens if I deactivate the plugin? =

The role stays, so existing Agent accounts keep their capabilities. The login blocks are removed with the plugin. Uninstalling the plugin removes the role, the application passwords it created, and the activity log. It does not delete the users.

== Changelog ==

= 1.5.0 =
Released 2026-09-30.

**Added**

* Four Agent personas (Web Dev, Editor, Writer, Analyst) so a site owner can pick what the account is for without flipping every switch.
* A Customize this Agent list of this site's abilities, grouped as Create, Read, Undo, Delete, and Other Abilities. Tools the plugin does not recognize stay off until someone turns them on.
* Save as Default, Reset to Default, and Factory Reset for a persona. Other customized agents on the site stay as they are.

**Changed**

* The Users menu item is Agents. The agent screen copy is rewritten for site owners.
* Job switches sit in two columns on wide screens. The form uses fieldsets and wrapping labels.
* Instructions start collapsed, with Reset, Generate, and Update Agent on that box.
* GitHub release notes are copied from this changelog.
* **Breaking:** The plugin text domain is `rf-agent-role` so language packs do not collide with another plugin named agent-role.

= 1.4.0 =
Released 2026-09-29.

**Added**

* Draw Agents, Settings, and Activity as folder tabs on one white panel.

= 1.3.0 =
Released 2026-09-29.

**Changed**

* Store each agent's publishing switches on that account. Turning one off leaves the shared Agent role, and the other agents, as they were.
* Copy switches that were already saved onto those accounts once.
* Use the same permission to open Add Agent and to save it.
* Save the MCP Adapter limit through the WordPress settings screen. An unchecked box turns it off.
* An ability that is on still has to pass that ability's own check. An ability that is off stays off.
* Load the Add Agent screen in wp-admin only.

= 1.2.0 =
Released 2026-09-29.

**Added**

* Add an Activity tab for what each agent tried: ability calls, REST writes, and account changes.
* Filter by agent, event type, and date. Show Success, Denied, Changed, and Error.
* Keep 30 days and 500 events per agent. Both limits are on the Settings tab, up to 365 days and 5,000 events.
* Link an agent's name to that agent's screen. Mark a deleted account, and include the deletion time when it was recorded.
* Leave out successful reads, passwords, instruction text, and request bodies.
* Remove the log when the plugin is uninstalled.

= 1.1.0 =
Released 2026-09-28.

**Added**

* Add a settings screen with Agents and Settings tabs, a New Agent window, and copy buttons for the one-time password.
* Open an agent to set capabilities, abilities, and connection instructions for that account only.
* Send those instructions when the agent connects through the MCP Adapter.
* Draft instructions from a short site brief with the WordPress AI client, when a connector can write text.
* Show MCP details only when the adapter is active and the agent has an application password.
* Keep people from being switched into the Agent role, and keep Agents from being switched into a person role.
* Revoke an application password with a form button, so a plain link cannot delete it.

= 1.0.0 =
Released 2026-09-28.

**Added**

* First release.

== Upgrade Notice ==

= 1.5.0 =
Agent personas and a Customize list for this site's abilities. Agents you already customized stay as they are.
