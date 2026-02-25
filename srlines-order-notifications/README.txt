=== SRLines Order Notifications ===
Contributors: srlines
Tags: order notifications, meta api, notifications, order confirmation, messaging
Requires at least: 6.6
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 5.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send order notifications via Meta API and process customer confirmations/cancellations automatically.

== Description ==

SRLines Order Notifications sends automated messaging notifications to customers when they place orders and when orders are fulfilled. Customers can reply with "0" to confirm or "1" to cancel their order, and the order status is automatically updated.

= Features =

* Automatic notifications for new orders via Meta API
* Order fulfillment/shipping notifications
* Customer order confirmation via messaging (reply "0")
* Customer order cancellation via messaging (reply "1")
* Automatic order status updates
* Dashboard with notification statistics
* Customer response tracking
* Simple webhook integration - just POST customer responses
* HPOS (High-Performance Order Storage) compatible
* Secure and production-ready

= How It Works =

1. Customer places an order
2. Plugin sends a message with order details via Meta API
3. Customer replies with "0" (Confirm) or "1" (Cancel)
4. CRM POSTs response to webhook URL: `/wp-json/wc-notifications/v1/customer-response`
5. Plugin updates order status automatically

= Webhook Format =

Configure your CRM to POST to this URL:
`https://yoursite.com/wp-json/wc-notifications/v1/customer-response`

With JSON payload:
`{"message": "0", "from": "+923001234567", "msg_id": "wamid.1234567890"}`

== Installation ==

1. Upload the `srlines-order-notifications` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to 'Order Notif' in the admin menu to access the dashboard.
4. Navigate to 'Settings' and enter your Meta API key.
5. Configure your notification templates and default phone number.
6. Set up your CRM to POST customer responses to the webhook URL shown in settings.

== Frequently Asked Questions ==

= What API does this plugin use? =

This plugin uses the Meta Business API via the SRLines CRM platform to send messaging notifications to customers.

= How do customers confirm or cancel orders? =

Customers receive a message with their order details. They reply with "0" to confirm or "1" to cancel their order. The plugin automatically updates the order status.

= Does this plugin support HPOS? =

Yes, this plugin is fully compatible with High-Performance Order Storage (HPOS).

= What happens if a customer does not respond? =

If a customer does not respond, the order remains in its current status. You can manually process responses from the admin dashboard.

= Can I resend a notification? =

Yes, you can resend notifications from the Notifications page in the admin panel for any failed or queued notifications.

= What phone number format is supported? =

The plugin supports international phone number formats. It automatically normalizes Pakistani phone numbers (e.g., 03001234567 becomes +923001234567).

== Screenshots ==

1. Dashboard showing notification statistics and recent customer responses.
2. Settings page for configuring API key and notification templates.
3. Order responses page showing customer confirmations and cancellations.
4. Notifications log showing sent, queued, and failed notifications.

== Changelog ==

= 5.1.0 =
* Full HPOS compatibility
* Improved customer response matching via msg_id
* Dashboard with notification statistics
* Order response tracking and manual processing
* Resend failed notifications
* Automatic cleanup of old records
* Webhook endpoint for CRM integration

= 2.0.0 =
* Initial public release
* Order confirmation and cancellation via messaging
* Meta API integration
* Admin dashboard and settings

== Upgrade Notice ==

= 5.1.0 =
Major update with HPOS support, improved response matching, and admin dashboard.
