# wp.org assets

Icon, banner, and screenshot images for the WordPress.org plugin listing page.
These are **not** part of the plugin itself — they live in SVN's `assets/`
directory (a sibling of `trunk/`), never inside the shipped zip. The
`10up/action-wordpress-plugin-deploy` GitHub Action reads this folder directly
and publishes its contents to that SVN path; nothing here needs to be listed
in `.distignore` beyond the folder itself.

## Expected files

| File | Purpose |
|------|---------|
| `icon-128x128.png` | Plugin icon, standard resolution |
| `icon-256x256.png` | Plugin icon, retina |
| `icon.svg` | Plugin icon, scalable (optional; used in place of the PNGs when present) |
| `banner-772x250.png` | Plugin page banner, standard resolution |
| `banner-1544x500.png` | Plugin page banner, retina |
| `screenshot-1.png`, `screenshot-2.png`, ... | Screenshots, numbered to match the `== Screenshots ==` section in `readme.txt` (caption N in the readme corresponds to `screenshot-N.png`) |

## Current state

Icons, banners, and `screenshot-1.png` … `screenshot-10.png` (matching the readme's ten captions) are in place. The screenshots were taken on a local test site with a zoology demo dataset (species tags, Term Groups hierarchies, demo posts); host names in JSON views and peer labels were neutralized.

`tag_table_top.png`, `term_groups_collapsed.png`, and `term_groups_expanded.png` are older, unnumbered images superseded by the numbered set; wp.org ignores them, so they can be deleted.
