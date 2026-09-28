=== Agent Role ===
Contributors: webdevmattcrom
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: users, roles, rest-api

An Agent role for accounts that cannot log in with a password.

== Description ==

Agent Role adds a user role named Agent. An Agent can publish and upload like an Author. The account cannot sign in on the login screen, cannot reset its password, and cannot open wp-admin.

Create the account from Users, then Add Agent. The password is shown once, in a window, with copy buttons. Open an agent from the list to choose what that account can do, which abilities it may run, and the instructions sent when it connects. Revoke the password from the list when you want to replace it.

The normal Add User screen does not offer the Agent role.

== Installation ==

1. Upload the `agent-role` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Users, then Add Agent.

== Frequently Asked Questions ==

= Can a person log in as an Agent? =

No. Password sign-in and password reset are refused. The application password works on the REST API only.

= Does this work with the WordPress MCP Adapter? =

Yes, and the adapter is optional. When it is active, the agent list can show the MCP endpoint and a client config. The password in that config is a placeholder, because WordPress does not keep the plaintext. A setting, off by default, limits the adapter's default server to Agent accounts. Each agent can also store instructions that the adapter sends when that account connects.

= What happens if I deactivate the plugin? =

The role stays, so existing Agent accounts keep their capabilities. The login blocks are removed with the plugin. Uninstalling the plugin removes the role and the application passwords it created. It does not delete the users.

== Changelog ==

= 1.1.0 =
* Add a settings screen with Agents and Settings tabs, a New Agent window, and copy buttons for the one-time password.
* Open an agent to set capabilities, abilities, and connection instructions for that account only.
* Send those instructions when the agent connects through the MCP Adapter.
* Draft instructions from a short site brief with the WordPress AI client, when a connector can write text.
* Show MCP details only when the adapter is active and the agent has an application password.
* Keep people from being switched into the Agent role, and keep Agents from being switched into a person role.
* Revoke an application password with a form button, so a plain link cannot delete it.

= 1.0.0 =
* First release.
