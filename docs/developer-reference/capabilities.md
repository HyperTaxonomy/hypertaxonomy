---
title: Capabilities
parent: Developer Reference
nav_order: 2
---

# Capabilities & User Roles

HyperTaxonomy defines six custom capabilities. Activation adds them to the Administrator and Editor roles, and deactivation removes them. Check them with `current_user_can()` rather than hard-coding role checks.

| Capability | Default roles | Controls |
|------------|--------------|----------|
| `manage_hypertaxonomy_settings` | Administrator | The Settings → HyperTaxonomy page, including export, import, and Tag Groups migration, and the index rebuild |
| `manage_term_relationships` | Administrator, Editor | The Terms menu, Term Groups, and editing term inclusions, containers, and groups |
| `manage_term_redirects` | Administrator, Editor | Set and remove term redirects |
| `manage_term_icons` | Administrator, Editor | Upload and remove term icons |
| `manage_term_visibility` | Administrator, Editor | Mark terms hidden/visible, and see hidden terms |
| `manage_term_sticky_posts` | Administrator, Editor | Pin posts to term archives |

These capabilities aren't derived from `manage_categories` or `manage_options`, so a role has only what it has been granted. Removing one from Editor takes effect, and a custom role that should use these features needs them added.

The Merge Terms, Convert Terms, and Term Relationship Types pages require `manage_options`.

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

Deactivating HyperTaxonomy removes the capabilities from Administrator and Editor only. Activating it adds them back, which undoes any removal from those two roles.
