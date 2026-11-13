---
title: Getting Started
nav_order: 4
---

# Getting Started

## Requirements & Installation

| Requirement | Minimum Version |
|-------------|----------------|
| WordPress | 6.6 |
| PHP | 7.4 |
| MySQL | 5.7 (or MariaDB 10.3) |

No additional plugins required. All features including Term Groups are built-in.

### Automatic Installation

1. Log into your WordPress admin
2. Go to **Plugins > Add New**
3. Search for "HyperTaxonomy"
4. Click **Install Now**, then **Activate**

### Manual Installation

1. Upload the plugin folder to `/wp-content/plugins/hypertaxonomy/`
2. Activate through the Plugins screen
3. Go to **Settings > HyperTaxonomy** to configure

## First steps

1. Go to **Settings > HyperTaxonomy > Features**. On the **General** subtab, enable the taxonomies HyperTaxonomy should manage, such as Tags. On each taxonomy's own subtab, check the features you want, such as **Term Relationships**.
2. Edit a tag and, in its HyperTaxonomy section, add another tag in the **Included Tags** field. Save.
3. Open the first tag's archive. Posts tagged with the included tag now appear there too, with no re-tagging.

To learn what else relationships can do, read [Concepts](user-guide/concepts.html), [Term Relationships](user-guide/term-relationships.html) and [Term Groups](user-guide/term-groups.html). Every option is described in the [Settings Reference](user-guide/settings-reference.html).

To turn the plugin off or remove it, see [Deactivation & Uninstall](user-guide/deactivation-uninstall.html): what stops working while it is off, what reactivation restores, and what uninstalling deletes.
