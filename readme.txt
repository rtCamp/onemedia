=== OneMedia ===
Contributors: Utsav Patel, Ahmar Zaidi, rtCamp
Donate link: https://rtcamp.com/
Tags: OneMedia, OnePress, Media Manager, WordPress, Media Manager, WordPress Automation, WordPress
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 1.1.3
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

OneMedia is a centralized media library solution for WordPress enterprises.

== Description ==

OneMedia is a centralized media library solution for WordPress enterprises. It enables you to store assets once on the Governing Site and automatically propagate them to every connected Brand Site, streamlining asset management across multiple environments.

**Why OneMedia?**

Managing media across multiple websites is time-consuming and repetitive. OneMedia eliminates redundant uploads and manual asset propagation, ensuring brand consistency and saving significant time.

**Key Benefits:**

* **Time Efficiency:** Eliminate redundant uploads across sites
* **Brand Consistency:** Ensure all sites display the latest approved assets
* **Workflow Optimization:** Centralize media management while maintaining site-specific control
* **Flexibility:** Share assets in Sync or Non-Sync Mode as needed
* **Sync Mode Sharing:** Any media shared in Sync Mode is automatically updated across all connected Brand Sites whenever changes are made on the Governing Site. These assets cannot be edited or deleted on the Brand Site ensuring consistency.
* **Sync Media Replacement:** In addition to updating metadata, you can also replace the actual media file for assets shared in Sync Mode.
* **Delete Prevention:** Media shared from the Governing Site cannot be deleted on the Brand Site. It's management is handled from the Governing Site only.
* **API Key Management:** Brand Sites generate unique API keys for secure communication with the Governing Site.

**Core Features:**

* Unified media library for all sites
* Sync & Non-Sync sharing Modes
* REST API integration for secure communication
* Site configuration for Governing and Brand Sites
* Media sharing page to select and distribute assets
* Sync status tracking for all shared assets

Note: Only JPG, PNG, WEBP, BMP, SVG and GIF files are supported.

**Brand Site Connection Management:**

* Add Brand Sites to manage from the Governing Site.
* Update Brand Site details or remove a Brand Site

**Media Management:**

* Share media from the Governing Site to Brand Sites in Sync or Non-Sync Modes
* Update metadata for media shared in Sync Mode.
* Replace media file for assets shared in Sync Mode.

**API Key Management:**

* Regenerate the API key for secure communication with the Governing Site.
* Disconnect from the Governing Site.

**Perfect for:**

* Multi-brand corporations
* Franchise operations
* Educational institutions
* E-commerce businesses
* Agencies managing multiple client sites

== Installation ==

1. Upload the OneMedia plugin files to the `/wp-content/plugins/onemedia` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Set up one site as the "Governing Site" for centralized management.
4. Configure other sites as "Brand Sites" and connect them to Governing Site using API keys.

== Frequently Asked Questions ==

= How does media sharing work? =

Media is uploaded once to the Governing Site and can be shared or synced to any connected Brand Site.

= Can I control which sites receive specific assets? =

Yes, you can select target sites when sharing media.

= What is the difference between Sync and Non-Sync Modes? =

* **Sync Mode:** Assets are kept up-to-date across all Brand Sites. Metadata changes or asset replacements on the Governing Site are automatically reflected on Brand Sites.
* **Non-Sync Mode:** Assets are shared once and not updated automatically. The idea is to be able to share multiple assets with multiple Brand Sites in one go.

= How do I add a new Brand Site? =

Go to **OneMedia → Settings**, click "Add Brand Site", and add the Brand Site details. Click on "Add Site" to register the Brand Site with the Governing Site.

= How do I check if media is synced? =

* On the Governing Site, go to **OneMedia → Media Sharing** and open the **Sync Media** tab. The Sync status is shown for each media asset and the tooltip shows which sites it's shared with.
* On the Brand Site, go to **WordPress Media Library** and you can check the **Sync Status** column in list Mode. In the grid Mode, you can check the Sync status icon on each media asset haveing title "SYNCED".

= Can I edit or delete shared media on Brand Sites? =

* Edit: You can edit the metadata of Non-Sync media on Brand Sites. However, changes made to the media on the Brand Site will not be reflected on the Governing Site.
* Delete: You cannot delete shared media on Brand Sites.

= My media share is failing. What should I do? =

Ensure that the API key is correct and the Brand Site is reachable. To do this you can go to OneMedia → Settings on the Governing Site and click the "Edit" button next to the Brand Site. Add a `/` after the URL and click on "Update Site". If the connection has any issues, a relevant error message will be displayed.

= Does this work for Multisite and Non-Multisite? =

Yes, OneMedia supports Subdirectory multisite and standard WordPress installations. As of now, Subdomain multisite installations have not been fully tested.

= Accidentally set incorrect site type during activation. How to fix? =

Uninstall the plugin and then reinstall it. During reactivation, select the correct site type.
Note: Uninstalling the plugin will remove all OneMedia data and settings but the media files will remain intact.

= Is this secure? =

Yes, all site-to-site communication uses API key authentication and validated requests.

== Screenshots ==

1. OneMedia Banner image

== Changelog ==

For the full changelog, please visit <a href="https://github.com/rtCamp/OneMedia/blob/main/CHANGELOG.md" target="_blank">GitHub repository</a>.

== Upgrade Notice ==

= 1.0.0 =
Initial release of OneMedia. Perfect for enterprises managing media across multiple WordPress sites.

== Support ==

For support, feature requests, and bug reports, please visit our [GitHub repository](https://github.com/rtCamp/OneMedia).

== Contributing ==

OneMedia is open source and welcomes contributions. Visit our [GitHub repository](https://github.com/rtCamp/OneMedia) to contribute code, report issues, or suggest features.

Development guidelines and contributing information can be found in our repository documentation.
