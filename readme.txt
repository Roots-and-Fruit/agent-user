=== Agent Role ===
Contributors: webdevmattcrom
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: mcp, agent, users, roles, security

Give your AI its own WordPress account, with only the access you choose.

== Description ==

Most sites connect an AI assistant by handing over an admin password, spinning up a hosted relay, or installing a plugin that exposes hundreds of tools and hopes you notice the dangerous ones.

Agent Role takes a different path. It creates a real WordPress user named Agent that can publish and upload like an Author, but cannot sign in on the login screen, cannot reset a password, and cannot open wp-admin. You create the account from **Users → Agents**, copy the application password once, and paste the setup prompt into Cursor, Claude, or whatever agent you already use.

Your login stays yours. If you stop trusting the connection, you revoke one password or delete one agent. The rest of your site does not move.

The plugin is built for people who want AI on a live WordPress site without treating the assistant like a second administrator.

= Benefits =

* **You stop sharing the keys to the whole house.** The AI connects with its own credentials, not your admin account. That alone cuts most of the "what if it deletes a plugin" anxiety.

* **An agent is not a person on your team.** Agents cannot log in as humans, cannot use the lost-password flow, and never show up on the normal Add User screen. People stay people. Agents stay agents.

* **You choose the job, not a wall of toggles.** Pick a persona (Writer, Editor, Analyst, Web Dev) and get a sensible starting point. Customize from there, or save your own default for the next agent you add.

* **Each agent gets its own scope.** Capabilities and abilities live on that account. Turn off delete posts for the writer agent while the editor agent keeps it. One agent's limits do not rewrite everyone else's.

* **You see what was attempted, not just what succeeded.** The Activity log records ability calls, REST writes, and account changes. Filter by agent, outcome, and date. Denied attempts show up too, so a blocked call reads differently from a broken one.

* **Onboarding that respects your password.** After you create an agent, you get a ready-made prompt for your agent plus the application password beside it. The prompt uses a placeholder for the secret so you are not pasting live credentials into chat.

* **Trust grows in steps.** Abilities from other plugins appear in your agent's list, grouped and off until you turn them on. You add reach as you get comfortable, not all at once on day one.

* **You can lock MCP to agents only.** When the official WordPress MCP Adapter is active, an optional setting (off until you turn it on) limits the default MCP server to Agent accounts. Your personal admin login cannot open that door by accident.

* **Revoke without drama.** Replace an application password from the agent list, or delete the agent entirely. Activity keeps a record either way.

= Integrations =

* **WordPress MCP Adapter (official).** Agent Role is a companion to the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), not a replacement for it. Install the adapter, create an agent, paste the generated config. Each agent can carry connection instructions the adapter sends when that account connects. Optional agents-only mode adds a gate on top. MCP Adapter 0.7 and later require WordPress 6.9 or newer because the Abilities API is in core. Agent Role itself still runs on WordPress 6.0 without the adapter.

* **WordPress Connectors.** If your site has an AI Connector that can write text (under **Settings → Connectors**), Agent Role can draft connection instructions from a short site brief. Generate, edit, save. Permission changes do not silently rewrite the box.

* **Abilities from other plugins.** Anything that registers with the WordPress Abilities API can show up on an agent's Customize screen, grouped as Create, Read, Undo, Delete, and Other. Each tool names its source plugin. You decide what this agent may call.

* **WP Rollback.** The Web Dev persona includes plugin update access with rollback in mind. If WP Rollback is on the site, the persona's instructions point the agent at rolling back a bad update, not just pushing forward.

* **Custom abilities you build.** Register your own abilities in your plugin or theme. They appear alongside the rest. Agent Role scopes them per agent the same way it scopes everything else.

* **Works with the agent you already use, MCP or not.** With the MCP Adapter active, the setup prompt adds your site as an MCP server in Cursor, Claude Desktop, Claude Code, VS Code, or any other MCP client. Without it, the prompt connects your agent through the WordPress REST API with the same application password, so you can start today and add MCP later. One agent account, one connection, one scope. Add a second agent when you want a second job with different limits.

= Support and resources =

* **Documentation:** [GitHub repository and wiki](https://github.com/Roots-and-Fruit/agent-user)
* **Support:** [WordPress.org support forum](https://wordpress.org/support/plugin/agent-role/)
* **Feature requests:** [GitHub Issues](https://github.com/Roots-and-Fruit/agent-user/issues)
* **Changelog:** Notable releases are in `CHANGELOG.md` in the plugin folder. GitHub release notes are copied from that file.

== Installation ==

1. Upload the `agent-role` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Open **Users → Agents** and create your first agent.
4. Copy the application password from the window. WordPress will not show it again.
5. Paste the setup prompt into your agent and replace the password placeholder with the one you copied.

HTTPS is required. WordPress only offers application passwords on secure sites.

== Frequently Asked Questions ==

= Can a person log in as an Agent? =

No. Password sign-in and password reset are refused. The application password works on the REST API and MCP only.

= Do I need the WordPress MCP Adapter? =

No. Agent Role works without it. Without the adapter, the setup prompt connects your agent through the WordPress REST API, using the agent's application password, and lists what that agent is allowed to do. Install the adapter later and new prompts set up an MCP connection for the same account. If you do install MCP Adapter 0.7 or later, your site needs WordPress 6.9 or newer.

= Does this replace the WordPress MCP Adapter? =

No. Agent Role manages agent accounts and scoping. The [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) is what turns WordPress abilities into MCP tools. Install both when you want a governed connection: create an agent here, connect through the adapter.

= Does this work with other MCP plugins? =

Agent Role does not ship its own MCP server. It adds agent identity and per-account limits on top of the official adapter. If another plugin also registers an MCP endpoint, pick one connection path and scope the agent account to match what you trust.

= Why can my agent see abilities in MCP discovery that are off on the Customize screen? =

MCP discovery lists every tool the site marks as MCP-public. Agent Role still enforces your per-agent switches when the agent tries to run one. A switch turned off means the call is refused, even if discovery showed the name. Customize is the source of truth for what may run. Discovery is a catalog of what exists on the site.

= What happens if I deactivate the plugin? =

The Agent role stays, so existing agent accounts keep their capabilities. The login blocks are removed with the plugin. Uninstalling the plugin removes the role, the application passwords it created, and the activity log. It does not delete the user accounts.

== Changelog ==

= 1.9.1 =
Released 2026-10-02.

**Fixed**

* MCP initialize again sends this agent's persona instructions with MCP Adapter 0.7.0, which moved to the new schema records.

**Changed**

* The readme notes that MCP Adapter 0.7 and later require WordPress 6.9, and that Agent Role works without the adapter over the REST API.
* The readme explains that MCP discovery lists every MCP-public tool on the site, while Customize switches still decide what each agent may run.

= 1.9.0 =
Released 2026-10-02.

**Added**

* Web Dev agents can keep plugins up to date. They list the updates WordPress has already found, read the WordPress.org changelog for the offered version, then update one plugin at a time. If the plugin was active, Agent Role loads the homepage in the same request and puts the previous version back when it hits a fatal error. Each update ends as updated, restored, or failed, with a plain-text reason.
* When WP Rollback is active, Web Dev agents can roll a plugin back to an older version listed on WordPress.org. Agent Role checks the plugin and version before anything changes, checks the homepage afterwards, and never runs a rollback and an update at the same time.
* Existing Web Dev agents, and a saved Web Dev default, get the new plugin-update switches turned on once after this update. Each switch can still be turned off per agent.
* Sites without the WordPress MCP Adapter get a setup prompt too. It connects the agent through the WordPress REST API with its application password, keeps the password out of the chat, and tells the agent how to find and run abilities. It lists what that agent is allowed to do, including each switched-on ability by name. View on the Agents list opens the same prompt.
* The Agents list has a Delete column. Delete asks for confirmation, then removes the agent account. Activity keeps a record that the account was deleted.

**Changed**

* Web Dev instructions walk the agent through plugin updates: list them, read the changelog, update one at a time, and use WP Rollback only when an update could not be restored or the user names a version.
* Activity shows what an ability reported, such as "Update a plugin: restored", so a restored or failed update reads as an error instead of a success.
* On the Agents list, the MCP info column is now Connection, and an agent without a password reads "Create a password to connect this agent." Revoke uses a grey undo icon instead of a red X.
* Agent Role screens ship their own fonts, Domine for headings and Nunito Sans for body text, and no longer load fonts from rootsandfruit.com.
* The footer's Docs, Feedback, and Support links go to the plugin wiki, GitHub feature requests, and the WordPress.org support forum.

**Fixed**

* The window that opens after you create or view an agent closes when you click Close, click outside it, or press Escape.

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

= 1.9.1 =
Fixes MCP persona instructions with MCP Adapter 0.7.0. No settings changes.

= 1.9.0 =
Web Dev agents can now update plugins one at a time, with an automatic restore if the homepage breaks, and roll back through WP Rollback. Existing Web Dev agents get these switches on once. Sites without MCP get a REST setup prompt.

= 1.8.0 =
The text domain is now agent-role, matching the plugin folder. Custom language files named for rf-agent-role need to use the new domain.

= 1.7.0 =
Creating an agent now gives you a prompt to paste into that agent, and the password once. A footer on each screen opens a short note about the project.

= 1.6.0 =
Each persona starts with its own connection instructions. The agent header shows a sparkle mark, the username, and the persona.

= 1.5.0 =
Agent personas and a Customize list for this site's abilities. Agents you already customized stay as they are.
