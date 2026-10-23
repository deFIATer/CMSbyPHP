# Instrukcja wdrożenia CMSbyPHP na serwer FTP

Paczka produkcyjna: **`CMSbyPHP-czupryniak-production.zip`** (ok. 1.8 MB)

---

### Krok 1: Wypakowanie i wrzucenie na FTP

1. Rozpakuj archiwum `CMSbyPHP-czupryniak-production.zip` na swoim komputerze (lub wrzuć plik ZIP bezpośrednio przez Menedżer Plików w cPanel / DirectAdmin i rozpakuj na serwerze).
2. Umieść pliki w katalogu głównym domeny:
   * Zazwyczaj jest to: `public_html/`, `www/` lub `httpdocs/`.
   * **Ważne:** Upewnij się, że ukryte pliki (zaczynające się od kropki, zwłaszcza główny `.htaccess`) również zostały przesłane!

---

### Krok 2: Wymagania serwera

* **PHP:** Wersja 8.1, 8.2 lub 8.3.
* **Rozszerzenia PHP:**
  * `pdo_sqlite` oraz `sqlite3` (włączone domyślnie na 99% hostingów).
  * `mbstring`, `fileinfo`, `gd` (lub `imagick`).
* **Serwer WWW:** Apache lub LiteSpeed z obsługą `mod_rewrite` (obsługiwane automatycznie przez plik `.htaccess`).

---

### Krok 3: Uprawnienia katalogów (CHMOD)

Upewnij się, że serwer WWW (PHP) ma uprawnienia zapisu do następujących katalogów:
* `data/` (oraz plik `data/cms.sqlite`, katalogi `data/cache/`, `data/logs/`, `data/protected_files/`) &rarr; **`775`** lub **`755`**
* `uploads/` (oraz `uploads/cache/`) &rarr; **`775`** lub **`755`**
* `plugins/` &rarr; **`775`** lub **`755`**

---

### Krok 4: Logowanie do panelu administracyjnego

1. Otwórz w przeglądarce adres:
   `https://twoja-domena.pl/admin/` lub `https://twoja-domena.pl/admin/login.php`
2. Dane logowania:
   * **Login:** `czupryniak`
   * **Hasło:** Twoje hasło ustawione w CMS

---

### Krok 5: Automatyczne wykrywanie domeny

* CMS automatycznie wykrywa właściwą domenę oraz protokół HTTPS.
* Jeśli strona będzie działać pod dedykowaną domeną, możesz opcjonalnie wejść w:
  **Panel &rarr; Ustawienia &rarr; Konfiguracja ogólna &rarr; Adres strony (Domena)** i wpisać pełny adres (np. `https://czupryniak.pl`).
