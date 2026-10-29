---
title: Capabilities
parent: Developer Reference
nav_order: 2
---

## 2. Capabilities & User Roles

HyperTaxonomy defines six custom capabilities. Always check capabilities using `HyperTaxonomy_Capabilities::current_user_can()` (or the standard `current_user_can()`) rather than hard-coding role checks.

| Capability | Default roles | Controls |
|------------|--------------|----------|
| `manage_hypertaxonomy_settings` | Administrator | Plugin settings pages |
| `manage_term_relationships` | Administrator, Editor | Edit term inclusions, containers, groups |
| `manage_term_redirects` | Administrator, Editor | Set and remove term redirects |
| `manage_term_icons` | Administrator, Editor | Upload and remove term icons |
| `manage_term_visibility` | Administrator, Editor | Mark terms hidden/visible |
| `manage_term_sticky_posts` | Administrator, Editor | Pin posts to term archives |

**Example:**

```php
if ( current_user_can( 'manage_term_relationships' ) ) {
    // Show relationship editing UI
}
```

Capabilities can be granted to or removed from any role using a capabilities plugin or custom code:

```php
// Grant icon management to Authors
add_action( 'init', function() {
    $role = get_role( 'author' );
    if ( $role ) {
        $role->add_cap( 'manage_term_icons' );
    }
} );
```

---

