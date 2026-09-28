=== Agent Role ===
Contributors: webdevmattcrom
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: users, roles, rest-api

An Agent role for accounts that cannot log in with a password.

== Description ==

Agent Role adds a user role named Agent. An Agent can publish and upload like an Author. The account cannot sign in on the login screen, cannot reset its password, and cannot open wp-admin.

Create the account from Users, then Add Agent. WordPress shows one application password, once. That password is for HTTP Basic authentication on the REST API. Revoke it from the same screen when you want to replace it.

The normal Add User screen does not offer the Agent role.

== Installation ==

1. Upload the `agent-role` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Users, then Add Agent.

== Frequently Asked Questions ==

= Can a person log in as an Agent? =

No. Password sign-in and password reset are refused. The application password works on the REST API only.

= Does this work with the WordPress MCP Adapter? =

Yes, and the adapter is optional. When the MCP Adapter plugin is active, the Add Agent screen also shows the MCP endpoint and a ready client config for Cursor, Claude Desktop, and other MCP clients. A checkbox on that screen, off by default, limits the adapter's default server to Agent accounts. With it on, an administrator's own application password no longer opens the MCP server.

= What happens if I deactivate the plugin? =

The role stays, so existing Agent accounts keep their capabilities. The login blocks are removed with the plugin. Uninstalling the plugin removes the role and the application passwords it created. It does not delete the users.

== Changelog ==

= 1.0.0 =
* First release.
