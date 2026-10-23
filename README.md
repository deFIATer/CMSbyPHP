# ⚡ CMSbyPHP

> **CMSbyPHP** is a lightweight, high-performance, and modular Content Management System and blog engine powered by **PHP 8.x** and **SQLite3**, forked and enhanced from ModoCMS.

[Polski (Pełna dokumentacja)](README.pl.md)

---

## 🌟 Key Features & Enhancements

- **`cmsbyphp-clean` Flagship Theme:** Ultra-fast HTML5 / Vanilla CSS3 / JS with zero framework bloat, light/dark mode persistence, reading progress bar, live client-side search, code copy buttons, and responsive typography.
- **Built-in RSS 2.0 Feed:** Native feeds available at `/feed`, `/rss.xml`, `/feed.xml`.
- **Enhanced Polish Slug Transliteration:** Proper diacritics mapping (`ą->a`, `ł->l`, etc.) instead of dropping letters.
- **Reading Time & Word Count:** Live statistics in admin post editor and badges on the blog.
- **Modernized CMSbyPHP Admin Panel:** Dashboard with system diagnostics, SQLite database metrics, quick drafts, and improved shortcuts.
- **Single File Database:** Fast SQLite 3 with Write-Ahead Logging (WAL) enabled by default.

---

## 🚀 Quick Start (Local Server)

```bash
php -S 127.0.0.1:8000 router.php
```

- **Frontend:** [http://127.0.0.1:8000/](http://127.0.0.1:8000/)
- **Blog Archive:** [http://127.0.0.1:8000/blog](http://127.0.0.1:8000/blog)
- **RSS Feed:** [http://127.0.0.1:8000/feed](http://127.0.0.1:8000/feed)
- **Admin Panel:** [http://127.0.0.1:8000/admin/](http://127.0.0.1:8000/admin/) (User: `czupryniak`, Password: `admin`)
