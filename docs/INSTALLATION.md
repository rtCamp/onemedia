# OneMedia - Installation and Setup Guide

This guide provides detailed instructions for installing and configuring OneMedia for your WordPress enterprise environment.

## System Requirements

| Requirement | Version |
| :---------- | :------ |
| WordPress   | \>= 6.8 |
| PHP         | \>= 8.2 |

## Installation Overview

OneMedia requires installation on **two types of sites**:

1. **Governing Site** (central dashboard)
2. **Brand Sites** (sites where media is to be shared)

## Step 1: Download and Install Plugin

1. Download the latest OneMedia plugin from [GitHub Releases](https://github.com/rtCamp/OneMedia/releases).
2. Upload the plugin files to `/wp-content/plugins/onemedia/` on Governing Site and all the Brand Sites.
3. If installing from source code, run the following commands in the plugin directory:

   ```bash
   composer install && npm install && npm run build:prod
   ```

## Step 2: Setup Governing Site (Central Dashboard)

**The Governing Site acts as your central control panel to manage all Brand Sites.**

1. **Activate Plugin:** Go to WordPress Admin → Plugins and activate OneMedia.
2. **Configure Site Type:** Upon activation, select **"Governing Site"** when prompted.
3. **Add Brand Sites:** Navigate to OneMedia → Settings and add each Brand Site using the configuration details copied from Brand Sites.

## Step 3: Setup Brand Sites (Managed Sites)

**Each Brand Site needs OneMedia plugin installed to receive plugin management commands.**

1. **Activate Plugin:** Go to WordPress Admin → Plugins and activate OneMedia on each Brand Site.
2. **Configure Site Type:** Upon activation, select **"Brand Site"** when prompted.
3. **Get API Key:** The plugin will redirect to OneMedia → Settings where you can copy or regenerate the API key.

## Step 4: Connect Brand Sites to Governing Site

**Register each Brand Site with your Governing Site for centralized management.**

1. **Access Governing Site:** Go to OneMedia → Settings on your Governing Site.
2. **Add Brand Site:** Click "Add Brand Site":
   - **Site Name:** Descriptive name for the Brand Site under 20 characters.
   - **Site URL:** Full URL of the Brand Site.
   - **API Key:** The API key generated on the Brand Site.
3. **Add Site:** Click "Add Site" to register the Brand Site with the Governing Site.

## Step 5: Share Media

**Share media items from the Governing Site to Brand Sites in [Sync and Non-Sync](../README.md#key-features) modes.**

1. On the Governing Site navigate to OneMedia → Media Sharing.
2. To share media in Non-Sync mode, use the **Non-Sync Media** tab. For Sync mode, use the **Sync Media** tab.
3. Add the desired media items to the respective tab by clicking "Add to Non-Sync Media" or "Add to Sync Media" button.
4. Select the media items you want to share with Brand Sites.
5. Click "Share Selected Media" and choose the target Brand Sites from the modal.
6. Click on "Share Media" on the modal to share the media.

> **Note:** The **Non-Sync Media** tab is already populated with supported Non-Sync media assets from the **WordPress Media Library**.

## Troubleshooting Installation

If you encounter issues during installation:

- **Issues & Bug Reports:** [GitHub Issues](https://github.com/rtCamp/OneMedia/issues)
- **Feature Requests:** [GitHub Discussions](https://github.com/rtCamp/OneMedia/discussions)
- **Documentation:** [Project Wiki](https://github.com/rtCamp/OneMedia/wiki)

## Next Steps

Once installation is complete, refer to the [main README](../README.md) for:

- Usage instructions.
- Plugin management features.

---

**Need additional help?**
Visit our [GitHub repository](https://github.com/rtCamp/OneMedia) for the latest updates and community support.
