# Automated Review Generator

Minimal starter plugin scaffold for 'Automated Review Generator' by Code Temple (https://codetemple.net).

Usage
-----

- After activation, open the admin menu: **Automated Reviews** (left sidebar) to view and edit plugin settings.
- There is also a **Settings** link on the plugin row in **Plugins → Installed Plugins** for quick access.

Settings (Admin UI)
-------------------

The plugin provides a settings form that controls how automated reviews will be generated (UI only at this stage).

Fields:

- `Average frequency (days)` — numeric (float), **default:** `5`. This controls the average interval (in days) between posted reviews. The system will add randomness around this average to vary the posting interval.
- `Average score (1.0 - 5.0)` — numeric (one decimal), **default:** `4.8`. Format must be `#.#` between `1.0` and `5.0`. Suggested realistic values: `4.7` or `4.8`.
- `Negative review percentage` — integer percentage `0`–`100`, **default:** `5`. Percentage of reviews that should be low-rated to make the distribution realistic.
- `Review prompt / guidance` — freeform textarea, **default:** a short guidance prompt. This text is used to instruct the review generation model on tone, points to mention, and length.

Safety & test mode
------------------

- By default the plugin runs in **test mode**: generated reviews are saved as **pending/test** (not publicly visible) and are clearly marked with metadata. This makes the plugin safe to use on development or staging environments.
- To enable public posting of generated reviews, manually opt-in via the **Enable live posting** checkbox on the settings page. **This is disabled by default** — use it only on non-production environments or with care.

Storage
-------

All settings are stored as a single option in the database: `arg_options` (option type: array). Use `get_option( 'arg_options' )` to fetch values programmatically.

Developer notes
---------------

- Settings are registered with the WordPress Settings API and validated/sanitized on save.
- The admin UI is implemented and persists values, but the automatic posting/generation logic is not yet implemented — that will be added incrementally.
- Translation-ready strings use the text domain `automated-review-generator`.

Next steps / TODO
-----------------

- Implement the scheduling and content generation logic (randomized intervals around the selected average, rating distribution, and posting to WooCommerce products).
- Add unit/integration tests and improve admin UI polish and help text.

If you'd like, I can (pick one):

1. Add README translation strings and update `readme.txt` for WordPress.org packaging.
2. Start implementing the review generation/scheduling logic in small, testable steps.

