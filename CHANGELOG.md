# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/2.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.9.1] - 2026-10-02

### Fixed

- MCP initialize again sends this agent's persona instructions with MCP Adapter 0.7.0, which moved to the new schema records.

### Changed

- The readme notes that MCP Adapter 0.7 and later require WordPress 6.9, and that Agent Role works without the adapter over the REST API.
- The readme explains that MCP discovery lists every MCP-public tool on the site, while Customize switches still decide what each agent may run.

## [1.9.0] - 2026-10-02

### Added

- Web Dev agents can keep plugins up to date. They list the updates WordPress has already found, read the WordPress.org changelog for the offered version, then update one plugin at a time. If the plugin was active, Agent Role loads the homepage in the same request and puts the previous version back when it hits a fatal error. Each update ends as updated, restored, or failed, with a plain-text reason.
- When WP Rollback is active, Web Dev agents can roll a plugin back to an older version listed on WordPress.org. Agent Role checks the plugin and version before anything changes, checks the homepage afterwards, and never runs a rollback and an update at the same time.
- Existing Web Dev agents, and a saved Web Dev default, get the new plugin-update switches turned on once after this update. Each switch can still be turned off per agent.
- Sites without the WordPress MCP Adapter get a setup prompt too. It connects the agent through the WordPress REST API with its application password, keeps the password out of the chat, and tells the agent how to find and run abilities. It lists what that agent is allowed to do, including each switched-on ability by name. View on the Agents list opens the same prompt.
- The Agents list has a Delete column. Delete asks for confirmation, then removes the agent account. Activity keeps a record that the account was deleted.

### Changed

- Web Dev instructions walk the agent through plugin updates: list them, read the changelog, update one at a time, and use WP Rollback only when an update could not be restored or the user names a version.
- Activity shows what an ability reported, such as "Update a plugin: restored", so a restored or failed update reads as an error instead of a success.
- On the Agents list, the MCP info column is now Connection, and an agent without a password reads "Create a password to connect this agent." Revoke uses a grey undo icon instead of a red X.
- Agent Role screens ship their own fonts, Domine for headings and Nunito Sans for body text, and no longer load fonts from rootsandfruit.com.
- The footer's Docs, Feedback, and Support links go to the plugin wiki, GitHub feature requests, and the WordPress.org support forum.

### Fixed

- The window that opens after you create or view an agent closes when you click Close, click outside it, or press Escape.

## [1.8.0] - 2026-10-01

### Changed

- **Breaking:** The text domain is `agent-role`, the same as the plugin directory. Language packs attach under that slug.

## [1.7.0] - 2026-10-01

### Changed

- After you create an agent, the window shows a prompt to paste into that agent, and the application password beside it. The prompt adds this site as its own MCP server, named for the site and that username, and leaves a placeholder so the password stays out of the chat.
- View on the Agents list opens that same prompt and explains that the password was shown only once, when the agent was created. The list no longer has its own copy button.
- Agents, Settings, and Activity share a white header with the agent mark, and a footer with a rating link, Docs, Feedback, and Support. The Roots and Fruit logo opens a short note about the project and the limits on an Agent account.
- Folder tabs leave room for the label. On the Agents list, links are underlined, the first column is Agent, Revoke is rust red, and View has an eye.
- On Activity, the filters stay on one line. How long events are kept, and how many there are, sit together under the description.

## [1.6.0] - 2026-09-30

### Changed

- The agent screen header shows a sparkle mark beside the name. Username and Persona sit on one line, and a Customized badge appears when the account differs from the default.
- Each persona ships its own default connection instructions, written for that job, not from the ability list.
- Customize this Agent no longer guesses whether a tool would refuse this account. Save as Default still drops a tool the agent could not run even with the switch on.
- New agents start as the Analyst persona.
- Customize this Agent sits on the chosen persona card. The other cards have a Select [Name] Persona button so the row stays even.
- Customize instructions is a closed section inside Customize this Agent.
- Opening Customize this Agent joins the chosen card to the tools as one tab, with the same colored border around both.
- Tools from other plugins sit in Create, Read, Undo, Delete, and Other with the built-in switches. Each one has a small badge naming the plugin.

### Fixed

- Opening an agent no longer prints PHP warnings from other plugins' abilities that need a post ID or other input.
- Generate instructions stays hidden unless this site has an AI client that can write text, so turning off AI Services does not leave a broken button. Agent Role still runs with no extra plugins.

## [1.5.0] - 2026-09-30

### Added

- Four Agent personas (Web Dev, Editor, Writer, Analyst) so a site owner can pick what the account is for without flipping every switch.
- A Customize this Agent list of this site's abilities, grouped as Create, Read, Undo, Delete, and Other Abilities. Tools the plugin does not recognize stay off until someone turns them on.
- Save as Default, Reset to Default, and Factory Reset for a persona. Other customized agents on the site stay as they are.

### Changed

- The Users menu item is Agents. The agent screen copy is rewritten for site owners.
- Job switches sit in two columns on wide screens. The form uses fieldsets and wrapping labels.
- Instructions start collapsed, with Reset, Generate, and Update Agent on that box.
- GitHub release notes are copied from this changelog.
- **Breaking:** The plugin text domain is `rf-agent-role` so language packs do not collide with another plugin named agent-role.

## [1.4.0] - 2026-09-29

### Added

- Draw Agents, Settings, and Activity as folder tabs on one white panel.

## [1.3.0] - 2026-09-29

### Changed

- Store each agent's publishing switches on that account. Turning one off leaves the shared Agent role, and the other agents, as they were.
- Copy switches that were already saved onto those accounts once.
- Use the same permission to open Add Agent and to save it.
- Save the MCP Adapter limit through the WordPress settings screen. An unchecked box turns it off.
- An ability that is on still has to pass that ability's own check. An ability that is off stays off.
- Load the Add Agent screen in wp-admin only.

## [1.2.0] - 2026-09-29

### Added

- Add an Activity tab for what each agent tried: ability calls, REST writes, and account changes.
- Filter by agent, event type, and date. Show Success, Denied, Changed, and Error.
- Keep 30 days and 500 events per agent. Both limits are on the Settings tab, up to 365 days and 5,000 events.
- Link an agent's name to that agent's screen. Mark a deleted account, and include the deletion time when it was recorded.
- Leave out successful reads, passwords, instruction text, and request bodies.
- Remove the log when the plugin is uninstalled.

## [1.1.0] - 2026-09-28

### Added

- Add a settings screen with Agents and Settings tabs, a New Agent window, and copy buttons for the one-time password.
- Open an agent to set capabilities, abilities, and connection instructions for that account only.
- Send those instructions when the agent connects through the MCP Adapter.
- Draft instructions from a short site brief with the WordPress AI client, when a connector can write text.
- Show MCP details only when the adapter is active and the agent has an application password.
- Keep people from being switched into the Agent role, and keep Agents from being switched into a person role.
- Revoke an application password with a form button, so a plain link cannot delete it.

## [1.0.0] - 2026-09-28

### Added

- First release.

[Unreleased]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.9.1...HEAD
[1.9.1]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.9.0...v1.9.1
[1.9.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Roots-and-Fruit/agent-user/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Roots-and-Fruit/agent-user/releases/tag/v1.0.0
