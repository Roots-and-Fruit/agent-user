# Agent Role

[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Tested up to WordPress 7.1](https://img.shields.io/badge/Tested%20up%20to-WordPress%207.1-21759b)](https://github.com/Roots-and-Fruit/agent-user/releases)
[![Latest release](https://img.shields.io/github/v/release/Roots-and-Fruit/agent-user?label=Release)](https://github.com/Roots-and-Fruit/agent-user/releases)
[![License GPL-2.0](https://img.shields.io/github/license/Roots-and-Fruit/agent-user)](https://github.com/Roots-and-Fruit/agent-user/blob/main/readme.txt)

Give an AI its own WordPress account. That account can publish and upload. It cannot sign in as a person, reset a password, or open wp-admin.

You keep your own login. If you stop trusting the connection, you revoke one password. Your admin password never goes into ChatGPT, Cursor, or Claude.

## What you get

- A user role named **Agent**, with the same publishing power as an Author.
- A screen at **Users → Add Agent** that creates the account for you.
- One application password, shown once, in a window with a Copy button on the site address, the username, the password, and the connection details.
- A page for each agent where you choose what that account can do, which abilities it may run, and the note it receives when it connects.
- A normal Users screen that does not offer the Agent role. People stay people. Agents stay agents.
- Revoke on the agent list when you want a new password.

An Agent can create, edit, publish, and delete its own posts, and it can upload files. It cannot change settings, install plugins, edit other people's posts, or manage users.

## Connect an AI

1. Install and activate Agent Role.
2. Open **Users → Add Agent**.
3. Enter a username and a display name. Create the account.
4. Copy the password from the window. WordPress will not show it again.
5. Paste it into the tool that needs access to your site.

That password works as the REST API password. Your real login password does not work for this account, and the lost-password email does not either.

If the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) is also active, the agent list can show a ready-made config for Cursor, Claude Desktop, and other MCP apps. The password in that config is a placeholder. Paste the one you copied when the account was created. A setting on the Settings tab, off until you turn it on, limits that connection to Agent accounts. Your own admin password then cannot open it. Each agent can also keep a short note that the adapter sends when that account connects.

## If you turn the plugin off

Deactivating Agent Role leaves the accounts in place. They keep the Agent role. The blocks on password login come back when you activate the plugin again.

Deleting the plugin removes the Agent role and the passwords this plugin created. It does not delete the user accounts. A password you created yourself, even one you named "Agent Role," is left alone.

## Requirements

WordPress 6.0 or newer. PHP 7.4 or newer. The site must use HTTPS, because WordPress only offers application passwords on a secure site.

## License

GPL-2.0-or-later. See [readme.txt](readme.txt).
