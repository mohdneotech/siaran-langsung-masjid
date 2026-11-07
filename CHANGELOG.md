# Changelog

## 1.0.1 — 2026-11-07
- Fix: on block themes (e.g. Twenty Twenty-Five) the full-width live broadcast band sat off-centre and made the page scroll sideways (WordPress forces `margin: auto !important` on children of constrained layouts). Full-width margins now take precedence. Classic themes were not affected.

## 1.0.0 — 2026-11-07
First public release (generalised from the Perepat Siaran Langsung 1.0.1 site build).
- Theme-independent front end (scoped Bootstrap-compatible CSS under `.ps-ui`, full-width live band on block themes), colour settings
- Front-page widget: automatic at the top of the front page content (setting), `[siaran_utama]`, or the `perepat_front_after_prayer` theme hook
- "Cipta halaman" button, Font Awesome auto/CDN/off, activation notice, settings link, uninstall data removal option
- Built-in GitHub Releases updater; refuses to activate alongside the old site-specific build
