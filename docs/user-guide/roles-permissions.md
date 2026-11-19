---
title: Roles & Permissions
parent: User Guide
nav_order: 13
---

# Roles & Permissions

HyperTaxonomy defines six custom capabilities. Activation adds them to the Administrator and Editor roles, and deactivation removes them. Check them with `current_user_can()` rather than hard-coding role checks.

| Capability | Default roles | Controls |
|------------|--------------|----------|
| `manage_hypertaxonomy_settings` | Administrator | The [Settings&nbsp;→ HyperTaxonomy](plugin-settings.html) page, including export, import, and Tag Groups migration, and the index rebuild |
| `manage_term_relationships` | Administrator, Editor | The Terms menu, [Term Groups](term-groups.html), and editing term [inclusions, containers, and groups](term-relationships.html). Also shows hidden terms in wp-admin, so relationship changes are never made without seeing the terms they affect |
| `manage_term_redirects` | Administrator, Editor | Set and remove term redirects |
| `manage_term_icons` | Administrator, Editor | Upload and remove [term icons](per-term-settings.html#term-icons) |
| `manage_term_visibility` | Administrator, Editor | [Mark terms hidden/visible](per-term-settings.html#term-visibility), and see hidden terms in wp-admin. On the front end, the **Hidden Terms** setting alone decides |
| `manage_term_sticky_posts` | Administrator, Editor | Pin posts to term archives |

These capabilities aren't derived from `manage_categories` or `manage_options`, so a role has only what it has been granted. If you remove one from Editor, editors lose that feature, and a custom role that should use these features needs them added.

The [Merge Terms](merge-convert.html#merge-terms), [Convert Terms](merge-convert.html#convert-terms), and [Term Relationship Types](term-relationships.html#custom-relationship-types) pages require `manage_options`, and so do the **⧉&nbsp;Merge** links on the [Synonyms](term-relationships.html#the-synonyms-page) page, which is otherwise part of the Terms menu.

**Example:**

```php
if ( current_user_can( 'manage_term_relationships' ) ) {
    // Show relationship editing UI
}
```

Capabilities can be granted to or removed from any role using a capabilities plugin or custom code. Role changes are stored permanently, so run this once, for example on your plugin's activation:

```php
// Grant icon management to Authors
register_activation_hook( __FILE__, function() {
    $role = get_role( 'author' );
    if ( $role ) {
        $role->add_cap( 'manage_term_icons' );
    }
} );
```

Deactivating HyperTaxonomy removes the capabilities from Administrator and Editor only. Activating it adds them back, which undoes any removal from those two roles. Uninstalling (deleting) HyperTaxonomy removes them from every role, including any you granted to other roles.
