=== Agent Role ===
Contributors: webdevmattcrom
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.0
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

= 1.8.0 =
Released 2026-10-01.

**Changed**

* **Breaking:** The text domain is `agent-role`, the same as the plugin directory. Language packs attach under that slug.

= 1.7.0 =
Released 2026-10-01.

**Changed**

* After you create an agent, the window shows a prompt to paste into that agent, and the application password beside it. The prompt adds this site as its own MCP server, named for the site and that username, and leaves a placeholder so the password stays out of the chat.
* View on the Agents list opens that same prompt and explains that the password was shown only once, when the agent was created. The list no longer has its own copy button.
* Agents, Settings, and Activity share a white header with the agent mark, and a footer with a rating link, Docs, Feedback, and Support. The Roots and Fruit logo opens a short note about the project and the limits on an Agent account.
* Folder tabs leave room for the label. On the Agents list, links are underlined, the first column is Agent, Revoke is rust red, and View has an eye.
* On Activity, the filters stay on one line. How long events are kept, and how many there are, sit together under the description.

= 1.6.0 =
Released 2026-09-30.

**Changed**

* The agent screen header shows a sparkle mark beside the name. Username and Persona sit on one line, and a Customized badge appears when the account differs from the default.
* Each persona ships its own default connection instructions, written for that job, not from the ability list.
* Customize this Agent no longer guesses whether a tool would refuse this account. Save as Default still drops a tool the agent could not run even with the switch on.
* New agents start as the Analyst persona.
* Customize this Agent sits on the chosen persona card. The other cards have a Select [Name] Persona button so the row stays even.
* Customize instructions is a closed section inside Customize this Agent.
* Opening Customize this Agent joins the chosen card to the tools as one tab, with the same colored border around both.
* Tools from other plugins sit in Create, Read, Undo, Delete, and Other with the built-in switches. Each one has a small badge naming the plugin.

**Fixed**

* Opening an agent no longer prints PHP warnings from other plugins' abilities that need a post ID or other input.
* Generate instructions stays hidden unless this site has an AI client that can write text, so turning off AI Services does not leave a broken button. Agent Role still runs with no extra plugins.

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

= 1.8.0 =
The text domain is now agent-role, matching the plugin folder. Custom language files named for rf-agent-role need to use the new domain.

= 1.7.0 =
Creating an agent now gives you a prompt to paste into that agent, and the password once. A footer on each screen opens a short note about the project.

= 1.6.0 =
Each persona starts with its own connection instructions. The agent header shows a sparkle mark, the username, and the persona.

= 1.5.0 =
Agent personas and a Customize list for this site's abilities. Agents you already customized stay as they are.
