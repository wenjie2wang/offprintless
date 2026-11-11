# Offprintless

*Offprintless* is a self-hosted reference manager for PDFs and bibliographic metadata. You keep your papers on your own
server, organise them in projects shared with the people you choose, search them, and (optionally) ask questions about
them with an AI model of your choice.

**This is a fork.** Offprintless is a fork of **[I, Librarian Free](https://github.com/mkucej/i-librarian-free)**, the
open-source PDF and reference manager written by **Martin Kucej**. Almost everything that makes it useful (the PDF
viewer, the importers for PubMed, arXiv, Crossref and more, the full-text search, the notes, the citation styles, the
whole application structure) is his work and the work of the contributors in his history. This fork starts from version
5.11.3 of that project and keeps its git history, so every original commit and author name is still there.

Offprintless is released under the same **GNU GPL v3** licence (see `LICENSE`). It is an independent
project: it is not affiliated with, endorsed by, or supported by the author of I, Librarian. Please do not report
problems with this fork to the original project.

## What is new compared with I, Librarian Free

Everything below was added or changed in this fork. The original application is otherwise unchanged in spirit: a classic
PHP application with SQLite, no build step, served by Caddy and PHP-FPM in Docker.

### Projects and who can see what
- **Projects have members with roles:** owner, editor or viewer. Items live only in projects (there is no global
  library any more), so a person sees only what their projects contain. One shared **Public** project is readable by
  everyone who is signed in.
- Owners add people and change their roles later. Project notes and discussions are for members only. PDF notes and
  highlights stay private to the person who made them, even on a public paper. Search facets and autocomplete lists do
  not reveal anything from projects you cannot see.
- **One copy of an article.** Adding the same article to another project links to the existing one; adding it twice to
  the same project is skipped with a warning. An article and its PDF are deleted only when no project holds it any more.
- Account types (administrator, user, guest) remain, and a suspended account stops working at once.

### Features the original keeps for its paid edition
- **Scopus, ScienceDirect and Patent search** connectors (in the original code they are upgrade-only stubs).
- **Activity logs**, visible to administrators only: full detail for 7 days, then daily counts for up to 30 days, then
  gone.

### Import
- **PDF only.** Office and image import (which needed LibreOffice) was removed, which also makes the Docker image about
  290 MB smaller. Tesseract stays for scanned PDFs.
- Rewritten **BibTeX and RIS parsers**, DOI and arXiv/PubMed identifier recognition (including a DOI read from the PDF
  itself), clear errors when a provider fails, and safer uploads: limits chosen for large files, unusual file names,
  file paths inside imported files are refused, and remote downloads cannot reach private addresses.

### Search
- Whole-word matching (`estim*` for a beginning or end, `"quotes"` for a phrase, `NOT` or `-` to leave a word out),
  authors in any order, year ranges, and results that are never stale.

### Optional AI features (everything is off by default)
- **Chat with a paper:** ask a question and get a streamed answer with citations that link to the PDF page.
- **Project notebook** (in the spirit of NotebookLM): tick papers in a project, then ask a question or produce a
  briefing, a comparison or an FAQ with a glossary. Answers can be copied into your project notes with one click.
- **Search by meaning** and **Similar papers:** find papers by what they are about, even when the words differ. The
  index is built from a button with a progress bar.
- Works with any **OpenAI-compatible** service: OpenAI, Anthropic's compatible endpoint, a local Ollama, Open WebUI,
  LM Studio or vLLM. Chat and embeddings can use different services (Anthropic has no embeddings). Offprintless does not
  ship a model server.
- **Consent and limits:** an administrator switch, a personal switch for each user with a legal notice they must
  acknowledge (accepting allows the text of their PDFs to be sent to the configured service), a separate switch for
  search by meaning, and daily and per-request token limits. Every AI request is checked against the same access rules
  as the rest of the application, and text from a PDF is never treated as instructions to the model.

### Interface
- A new look with two themes, light and dark (the original's photo-backdrop look is gone), a new tab icon, readable PDF tiles, and
  a page counter such as "5 /10" in the PDF viewer.

### Security and operations
- Sign-in throttling with one error message for every failure, safer defaults, and a restricted activity log.
- A **weekly backup** (`bin/weekly-backup.sh`) that takes consistent copies of the databases from the running site,
  keeps dated snapshots with unchanged PDFs hard-linked, and a script to check a backup without touching the site (see
  `bin/BACKUP.md`).
- A plain-PHP **test suite** of about 40 scripts (`sh tests/run.sh`) that boots the real application. Tests for the AI
  features use a fake model and never touch the network.
- English only. The original's German translation file is still in the repository but is not kept up to date.

## What it needs
PHP 8.1 with the extensions `sqlite3, gd, curl, intl, xml, json, mbstring, zip` (and `ldap` if you use LDAP), plus the
programs Poppler (`pdftotext`, `pdfinfo`, `pdftoppm`, `pdftohtml`) and Ghostscript. Tesseract OCR is optional. In Docker
all of this is in the image.

## Install
- **[Docker](README-Docker.md)** (the way it is run today)
- [Linux, manual](README-Linux.md)
- [Mac OS X, manual](README-Mac.md)
- Windows, manual: see below.

There are no ready-made installers for Offprintless. The installers and packages published by the original project
install *I, Librarian*, not this fork. The Docker instructions below still mention a `tar.xz` package: that is how the
original is distributed; with this fork, build the image from a checkout of this repository
(`docker compose build`, see `docker-compose.yml` and `Dockerfile.local`).

### Windows manual installation
The Windows and Mac instructions come from the original project and have not been tried with Offprintless.
  * A running *Apache 2.4+* and *PHP 8+* are required. Install them using a Windows installer like WAMP.
  * Edit Apache configuration file (httpd.conf). Append this at the end using Notepad and edit as needed:

```apache_conf
Alias /offprintless "C:\Offprintless\public"
<Directory "C:\Offprintless\public">
    AllowOverride All
    # Allow access from this computer.
    Require local
    # Allow access from intranet computers.
    Require ip 10
    Require ip 172.16 172.17 172.18 172.19 172.20
    Require ip 172.21 172.22 172.23 172.24 172.25
    Require ip 172.26 172.27 172.28 172.29 172.30 172.31
    Require ip 192.168
    # Insert Allow from directives here to allow access from the internet.
    # "Require all granted" opens access to everybody.
</Directory>
```

  * You may wish to alter who has access (e.g. to allow access from more IP numbers or domain names) - see the
    Apache [Authentication and Authorization HOWTO](https://httpd.apache.org/docs/2.4/howto/auth.html) for details.
  * Restart the server.
  * Unzip the *Offprintless* files into `C:\Offprintless`.
  * You may change `C:\Offprintless` to any directory where you want to have *Offprintless*,
    including an external drive.
  * Now you can access your library in a browser at http://127.0.0.1/offprintless
  * *Optional.* You can install Tesseract OCR to enable OCR of scanned PDFs. Only PDF files can be imported.

### First use
* Note on security: These installation instructions allow access to your library only from local computer
  or an internal network.
* In order to start *Offprintless*, open your web browser, and visit:
  [http://127.0.0.1/offprintless](http://127.0.0.1/offprintless)
* Replace `127.0.0.1` with your static IP, or qualified server domain name, if you have either one.
* Create an account (the first account is the administrator) and head to `Administrator > Software details` to see if
  everything checks fine.
* You should also check `Administrator > Global settings` to run *Offprintless* the way you want. The AI features are
  switched on there, and again by each user in their own settings.
* Set up the weekly backup described in `bin/BACKUP.md` before you rely on the library.

### Un-installation
* Un-install all programs that you installed solely to use *Offprintless*.
* These may include Apache, Nginx, and PHP - using package managers like Apt, Homebrew, or Macports will make this task easier. **Note: You might have other programs using these. Only remove if sure.**
* Delete the *Offprintless* directory.

## Credits

- **Martin Kucej**, author of [I, Librarian Free](https://github.com/mkucej/i-librarian-free), and the contributors to
  it, among them **Douglas Fraser**. The original project is the foundation of this one. If you find Offprintless
  useful, please take a look at the original and consider supporting it.
- The libraries that the application bundles, each under its own licence (see their folders): Guzzle, HTML Purifier,
  the IEEE Xplore PHP SDK, TinyMCE, citeproc-js, jQuery, jQuery UI, Bootstrap, Backbone, Lodash, Popper, Vue, Dexie,
  lunr, Cropper, MetisMenu, the Material Design Icons font and the Noto Sans Display font.
- The programs it relies on: PHP, SQLite, Caddy, Poppler, Ghostscript and Tesseract.
- The modifications in Offprintless are by **Wenjie Wang**, with the help of an AI coding assistant (Claude Code).

## Security
To report a vulnerability in Offprintless, see `SECURITY.md`.

## Licence
GNU General Public License, version 3 (the Docker labels of this fork say `GPL-3.0-only`); see `LICENSE`. As the licence
requires, the source of this fork is available in this repository, and if you distribute your changes to it you must
share them under the same licence.
