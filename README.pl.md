# ⚡ CMSbyPHP (Fork ModoCMS)

> **CMSbyPHP** to lekki, nowoczesny i zoptymalizowany pod kątem szybkości system CMS oraz silnik blogowy oparty na **PHP 8.x** i bazie **SQLite3**. Powstał jako ulepszony fork ModoCMS, rozbudowany o natywny kanał RSS 2.0, dedykowany motyw `cmsbyphp-clean` (HTML5 / Vanilla CSS3 / JS bez zbędnego balastu), inteligentną transliterację polskich slugów, licznik słów z estymacją czasu czytania oraz nowoczesny panel zarządzania.

---

## 🌟 Główne ulepszenia w CMSbyPHP

1. **Nowoczesny motyw blogowy `cmsbyphp-clean`:**
   - Czysty HTML5 i nowoczesne CSS Custom Properties (CSS variables).
   - Natywny, zapamiętywany przełącznik trybu ciemnego/jasnego (Dark/Light mode) bez efektu migania (FOUC).
   - Pasek postępu czytania u góry ekranu w artykułach (`#reading-progress`).
   - Wyszukiwarka wpisów na żywo w archiwum bloga (natychmiastowe filtrowanie w JavaScript bez przeładowania strony).
   - Przycisk kopiowania kodu w blokach `<pre><code>` z powiadomieniem.
   - Płynne przyciski udostępniania i kopiowania linku z powiadomieniem toast.
   - Paginacja, podział na kategorie, chmura tagów oraz powiązane/sąsiadujące artykuły (Następny/Poprzedni wpis).

2. **Wbudowany kanał RSS 2.0:**
   - Dostępny pod adresami: `/feed`, `/rss.xml`, `/feed.xml`.
   - Automatycznie generuje poprawny XML z najnowszymi opublikowanymi artykułami, linkami kanonicznymi i metadanymi.

3. **Usprawnienia dla języka polskiego:**
   - Inteligentny auto-slug w edytorze wpisów: transliteruje polskie znaki diakrytyczne (`ą->a`, `ć->c`, `ę->e`, `ł->l`, `ń->n`, `ó->o`, `ś->s`, `ź->z`, `ż->z`) zamiast je usuwać.
   - Dedykowany przycisk `⚡ Auto-slug` w edytorze.

4. **Licznik słów i czasu czytania:**
   - Na bieżąco zlicza słowa i wylicza czas czytania w edytorze panelu admina.
   - Wyświetla estymację (np. `⏱️ 4 min czytania`) na kartach wpisów i w nagłówku artykułu.

5. **Nowy pulpit administracyjny:**
   - Zmodernizowany nagłówek z brandingiem CMSbyPHP.
   - Szybkie skróty w pasku górnym (`+ Nowy wpis`, `Podgląd strony`).
   - Karta diagnostyki i stanu systemu (wersja PHP, rozmiar bazy SQLite, status WAL mode, linki do sitemapy i RSS).
   - Szybki szkic (`Quick Draft`) z możliwością natychmiastowego przypisania do kategorii.

---

## 🚀 Szybki start (Lokalne uruchomienie)

Wymagania: **PHP 8.0+** z rozszerzeniem `pdo_sqlite` (wbudowane w PHP).

1. Uruchom wbudowany serwer deweloperski z dołączonym routerem:
   ```bash
   php -S 127.0.0.1:8000 router.php
   ```

2. Otwórz w przeglądarce:
   - Strona bloga: [http://127.0.0.1:8000/](http://127.0.0.1:8000/)
   - Archiwum bloga: [http://127.0.0.1:8000/blog](http://127.0.0.1:8000/blog)
   - Kanał RSS: [http://127.0.0.1:8000/feed](http://127.0.0.1:8000/feed)
   - Panel administracyjny: [http://127.0.0.1:8000/admin/](http://127.0.0.1:8000/admin/)

---

## 🔑 Domyślne dane logowania do panelu

- **Użytkownik:** `czupryniak`
- **Hasło:** `admin` *(zalecana zmiana po pierwszym zalogowaniu w zakładce Profil)*

---

## 📁 Struktura projektu

- `admin/` — Zmodernizowany panel administracyjny CMSbyPHP.
- `core/` — Lekki, zoptymalizowany rdzeń (Router, baza danych SQLite, autoryzacja, SEO, RSS).
- `data/` — Baza danych `cms.sqlite` (pojedynczy plik) oraz kopie zapasowe i logi.
- `themes/cmsbyphp-clean/` — Flagowy, minimalistyczny i ultra-szybki motyw blogowy HTML5/CSS3/JS.
- `uploads/` — Wgrane multimedia i grafiki wpisów.
- `router.php` — Obsługa routingu dla wbudowanego serwera PHP.
- `modoconfig.php` — Zaawansowana konfiguracja (strefa czasowa, język, debugowanie).

---

## 🛡️ Wdrożenie na serwer produkcyjny

CMSbyPHP działa na dowolnym hostingu współdzielonym lub VPS obsługującym PHP 8.x:
1. Wgraj pliki do katalogu głównego domeny (np. `public_html`).
2. Upewnij się, że katalogi `data/` oraz `uploads/` mają prawa zapisu (`chmod 775`).
3. Serwer Apache z modułem `mod_rewrite` automatycznie korzysta z dołączonego pliku `.htaccess`.
