# Semantic search and AI plug-ins: plan

Status: proposal. Nothing here is implemented. Section 9 records the decisions made on 2026-11-10 and the few that remain.

## 1. Goals and non-goals

Goals

- Find papers by meaning ("ways to handle missing outcome data") in addition to the existing whole-word search, which stays exactly as it is.
- Show related papers for an item.
- Chat with one paper, then several, with citations that point to a page.
- Give a self-hoster a small, documented way to add AI features with any tool, without editing the core.

Non-goals

- Replacing whole-word search. Semantic search is a separate mode.
- Training models, or running a model inside PHP.
- Audio overviews and anything that needs a new subsystem.
- Sending any text anywhere by default.

## 2. What was measured (so the plan fits this app)

| Finding | Source | Consequence |
|---|---|---|
| The production library has 1,489 items. None has an abstract or a keyword. 1,482 have PDF text. | read-only query on `tars` | A "paper vector" cannot come from the abstract. It is built from the title plus the first part of the PDF text. |
| The PDF text is about 90 MB, roughly 23 million tokens (about 63,000 characters per paper, longest 1.4 million). | same | Embedding every chunk of every paper is possible but is a large job. Papers are embedded first. |
| PHP 8.1 PDO cannot load SQLite extensions (`loadExtension` arrives in PHP 8.4). | image | `sqlite-vec` is not usable from this app without a second SQLite connection. Vectors are compared in PHP. |
| Comparing a query with stored float32 vectors in plain PHP: 22 ms for 1,500 x 768, 288 ms for 20,000 x 768, 1.3 s for 90,000 x 384. | measured in the image | Paper-level search is instant. Whole-library chunk search is about 1.3 s at 384 dimensions and roughly double at 768 (extrapolated). |
| There is no cron or worker process in the image. PHP request time is 300 s. | `start.local.sh` | Embedding needs a background worker, started by the container script. |
| `tars` has 28 cores and 64 GB of memory. Whether it has a GPU is not known. | `nproc`, `free` | Local embedding is plausible. Its speed is not measured and is the first thing to benchmark. |
| `pdftotext` output keeps form feeds between pages, and the app already stores it. | `PdfModel::_save` | Chunks can carry page numbers cheaply, so chat citations can say "p. 7". |
| API keys are kept in the `settings` table as plain text. The `IL_API_*` environment variables override them. | `AppSettings::apiKey` | AI keys must come from the environment or be masked and never logged. |

## 3. Architecture

Four layers. Each depends only on the one below it.

```
Features        semantic search mode, related papers, chat with a paper, synthesis
Services        SemanticSearch, Retriever, ChatSession
Index           Chunker, EmbeddingJobs, VectorStore            (data/database/ai.db)
Providers       EmbeddingProvider, ChatProvider                 (adapters)
```

### 3.1 Providers

```php
interface EmbeddingProvider {
    public function id(): string;                      // "openai:text-embedding-3-small"
    public function dimensions(): int;
    /** @param string[] $texts  @param string $kind 'document'|'query'  @return float[][] */
    public function embed(array $texts, string $kind): array;
}

interface ChatProvider {
    public function id(): string;
    public function contextWindow(): int;              // for the context-usage indicator
    public function stream(array $messages, callable $on_token, array $options = []): Usage;
}
```

- One adapter speaks the OpenAI-compatible HTTP API. It covers OpenAI, Ollama, LM Studio, vLLM and most gateways, so local and cloud need no separate code.
- Anthropic has no embeddings endpoint. Chat uses a small native adapter, and embeddings use any other provider.
- A deterministic fake provider (hash-based vectors, canned replies) is used by every test, so tests never touch the network.
- The provider's base URL is admin configuration only. It is never taken from a user request, and it is allowed to be a private address (Ollama is usually `http://host:11434`). The guarded downloader used for user URLs must not be reused for it.

### 3.2 Storage

A new database `data/database/ai.db`, attached to the main connection as `ai`, so the existing visibility query works unchanged:

```sql
SELECT ... FROM ai.vectors v WHERE v.item_id IN (<visibleItems()>)
```

Tables

- `vectors(item_id, kind, chunk_no, page, model_id, dims, vec BLOB, text_hash, created)` with `kind` = `paper` or `chunk`.
- `jobs(id, type, item_id, model_id, state, attempts, error, created, finished)`.

Why a separate file: vectors can be rebuilt, so the file is optional in backups, can be deleted to start over, and does not grow `main.db`. The cost is that foreign keys do not cross databases, so destroying an article must delete its vectors. There is already one place where an article's files are removed after the database commits (`ItemModel::removeItemFiles`); the vector delete goes there, plus a periodic sweep for orphans.

Vectors are keyed by `model_id`. Changing the model re-embeds in the background and switches over when it is complete, so search never mixes models.

### 3.3 Indexing

- **Paper vector**: title, then the first 2,000 characters of the PDF text (usually the abstract). One per item. 1,489 items is about 3 million characters, so this is cheap on any provider.
- **Passage vectors** are made only when a paper is searched or chatted with (section 12), never for the whole library in advance: about 1,000 characters on sentence boundaries with 150 overlap, each tagged with its page. A library-wide passage index (about 94,000 passages for the production library) is not planned.
- The text comes from the stored PDF text, not from `full_text_index` (which is lower-cased and stripped of accents and punctuation).
- A worker process (`php bin/worker.php`, started by the container script next to php-fpm and Caddy) takes jobs, calls the provider through the existing queue lanes for rate limiting, retries with back-off, and records errors. A job is enqueued when an item is created, edited or has its PDF replaced.

### 3.4 Search

- Mode "Meaning": embed the query, compare with the paper vectors the user can see, return the top results with a score. No caching of results (the stale-result bug in the old search came from caching).
- Mode "Meaning + words": reciprocal-rank fusion of the whole-word result and the semantic result, so exact author or title matches stay on top.
- "Similar papers" on an item page: the item's own vector against the rest.
- Results show a plain-language reason: the best matching passage (for chunk search) or the title.

### 3.5 Chat

- One paper first: its passages are made the first time the paper is searched or chatted with (a paper has a few hundred), embedded once and kept; the best few are retrieved and sent with the question. No library-wide passage index is needed.
- Streaming uses the existing event-stream response type.
- Answers cite chunks as `[1]`, `[2]`; each citation links to the item and page.
- A context meter shows prompt tokens against the model's window, as in the earlier design notes.
- Several papers (phase 5): retrieve per paper, then answer in two steps (map, then reduce), so the meter stays honest.

## 4. Privacy and security rules (apply to every phase)

1. **Off by default, and the user's own switch is the gate.** An administrator configures the API and may switch AI features off for the whole server ("use AI features"). Each user has their own switch (`ai_enabled`), **off by default**. Turning it on, every time it goes from off to on, shows a notice that states plainly that accepting it **allows the user's PDF text to be sent to the configured outside AI service**, and explains the legal risk of doing so with licensed journal PDFs. It needs an explicit acknowledgement. The server records the notice version and the time (`ai_ack_version`, `ai_ack_at`) and refuses to turn it on without the current version; changing the notice text bumps `AiConsent::VERSION`, which voids earlier acknowledgements. Every AI feature must ask `Librarian\Security\AiConsent::allowed($user_settings)`, in addition to the administrator's master switch. There is no separate "allow text to leave this server" switch and no per-project exclusion: the user's acknowledgement is the permission. Built and tested in `tests/ai_consent_test.php`.
   Each user also has their own switch, **off by default** (`ai_enabled`). Turning it on, every time it goes from off to on, shows a notice that states plainly that accepting it **allows the user's PDF text to be sent to the configured outside AI service**, and explains the legal risk of doing so with licensed journal PDFs. It needs an explicit acknowledgement. The server records the notice version and the time (`ai_ack_version`, `ai_ack_at`) and refuses to turn it on without the current version; changing the notice text bumps `AiConsent::VERSION`, which voids earlier acknowledgements. Every AI feature must ask `Librarian\Security\AiConsent::allowed($user_settings)`, in addition to the administrator's switches and the project mark. All of them must allow. Built and tested in `tests/ai_consent_test.php`.
2. **Access first.** Every vector query, retrieval and chat is filtered by the same visibility query as the library. A user without access never gets an item id, a passage, a count or a score. A test modelled on the existing privacy tests proves it, including the moment a member is removed.
3. **Licensed content.** The library may hold paywalled journal PDFs shared with invited users. Sending their text to a third-party service may be restricted by the publisher's terms. The notice in rule 1 tells each user this before they allow it; whether it is permitted is their responsibility, not the application's. (Not legal advice.) **Whose consent counts:** a paper is sent to the API only because a user who has consented asked for it (added it, or ran an AI feature on it), and only that user, and others who have consented, get results built from it. A paper that only users who have not consented can see is never sent. This is a design call for the owner to confirm.
4. **Retrieved text is data, not instructions.** PDF text can contain prompt injection. Model output never triggers a write by itself: plug-ins and chat propose, a person applies.
5. **Escape the output.** Model text is escaped or rendered through a sanitiser before it reaches the page.
6. **Keys and logs.** Keys come from the environment or are masked in the UI. Prompts, passages and keys are not written to logs; usage (counts, tokens, model) is, under the existing 30-day retention.
7. **Budgets.** A per-user daily token limit and a per-request size cap, set by the administrator.
8. **Deletion.** Vectors go with the article, and with the model when it is changed.

## 5. The plug-in framework

### 5.1 Idea

A plug-in adds capabilities. The core knows a small, fixed set of extension points; a plug-in declares which it uses.

| Extension point | What it adds | Example |
|---|---|---|
| `embedding_provider`, `chat_provider` | a model backend | a Voyage or Gemini adapter |
| `item_action` | a button on an item or a list that returns a result | "Suggest tags", "Summarise", "Extract methods" |
| `search_mode` | an entry in the search dropdown | "Cited by", "Reranked" |
| `connector` | a place to fetch references from (already exists in the core) | a new bibliographic API |
| `job` | background work on a schedule or on an event | nightly re-embedding |

### 5.2 Package

A plug-in is a folder in `data/plugins/<id>/` (the data folder is mounted from the host, so plug-ins survive image rebuilds) with a `plugin.json`:

```json
{
  "id": "tag-suggester",
  "name": "Tag suggester",
  "version": "0.1.0",
  "api": 1,
  "runtime": "http",
  "endpoint": "http://tag-suggester:8000",
  "capabilities": [
    { "type": "item_action", "label": "Suggest tags", "input": "item_text" }
  ],
  "permissions": ["read:item_text", "propose:tags"],
  "settings": [ { "key": "model", "label": "Model", "type": "text" } ]
}
```

`api` is an integer. The app refuses a plug-in newer than it understands.

### 5.3 Two runtimes

| | `http` (recommended) | `php` |
|---|---|---|
| Runs | in its own container or process, any language | inside the app |
| Isolation | the app sends only what the current user may see | none: it has the app's full access |
| Best for | AI tools (most are Python), anything untrusted | tiny model adapters that need streaming |
| Who may install | administrator | administrator, and only code they trust |

State this plainly in the admin page: a `php` plug-in is the application. Its declared permissions are consent text, not enforcement. For `http` plug-ins the app enforces them.

**HTTP protocol (v1)**

- The app calls `POST {endpoint}/v1/run` with the capability, a request id, and only the data that capability needs, already filtered by the user's access. The request is signed with an HMAC and a short expiry.
- The plug-in answers with JSON. For an `item_action` the app shows the result; if it contains proposed changes (tags, a note), the person confirms them and the app applies them through the normal models with that person's role. Plug-ins never write on their own.
- A plug-in that needs more data may call back to a narrow endpoint with a token scoped to that user and request. The token expires in minutes and passes through the same access checks.
- Plug-in calls are rate limited and size capped per user, and failures are shown as a clear message, not a blank page.

### 5.4 Core pieces

- `PluginRegistry`: reads manifests (cached by file time), validates them, exposes `capabilities($type)`.
- Admin page "Plug-ins": list, enable or disable, view permissions, test connection, last errors.
- Views ask the registry for buttons and modes; one controller action, `plugin/run`, checks sign-in, that the plug-in is enabled, that the capability exists, that the user may see the item, then the limits, then dispatches.
- The core's own semantic search and chat are built as ordinary users of the same registry and provider interfaces.

### 5.5 External AI tools (separate from plug-ins)

New AI tools often want to use the library rather than extend the app. For that, per-user API tokens (stored hashed, with read or write scope) and a read-only Model Context Protocol server exposing `search_library`, `get_item`, `get_item_text` and `list_projects`, all going through the same models so project access applies. This also serves the earlier plan for pulling and pushing references with `curl`.

## 6. Phases

Each phase ends with passing tests and something a person can try. Sizes are relative: S small, M medium, L large.

| Phase | Content | Size |
|---|---|---|
| 0. Benchmark | Embed 50 papers with two or three embedding models from the API that will be used (the cloud model chosen, plus `nomic-embed-text` and `mxbai-embed-large` through the host Ollama as a free comparison); measure time and cost; build 20 known-item queries from the real library (a paraphrase of a title should find the paper) and compare. Decide model and dimensions. Papers sent in the benchmark are only those whose holder has agreed to it. | S |
| 1. Foundation | `ai.db`, settings, the two interfaces, the OpenAI-compatible adapter (cloud and any compatible server), the fake provider, job table, worker process and its compose profile, chunker, paper vectors, admin "AI" page (master switch, status, test connection, progress). The per-user switch and notice are done. | M |
| 2. Semantic search | VectorStore search, "Meaning" and "Meaning + words" modes, "Similar papers", visibility and removal tests, deletion hooks. | M |
| 3. Inside a paper | Search inside an opened paper (words, then meaning), passages with page links, then chat with the paper: retrieval, streaming, citations with pages, context meter, budgets. | M |
| 4. Plug-in framework v1 | Registry, manifest, `http` runtime and protocol, admin page, `plugin/run`, two example plug-ins (tag suggester, summariser), `docs/plugins.md`. The API is frozen only after the second example works. | L |
| 5. Extras | Multi-paper synthesis, API tokens and the MCP server, optional reranking. A library-wide passage index only if it is ever wanted. | L |

The plug-in framework comes after the first two features on purpose: an interface designed before it has had two real users usually has to change.

## 7. Tests

- Provider contract tests run against the fake provider and, when a key or local server is configured, against a real one.
- Visibility tests: a viewer, a removed member, a guest, an admin; direct item ids; counts; "similar papers" for an item the user cannot see.
- Index tests: a new item gets a job; an edit refreshes; a deleted article loses its vectors; a model change does not mix models.
- Search tests: known-item queries from the Phase 0 set must find their paper in the top five.
- Failure tests: provider down, slow, wrong dimensions, malformed answer, quota exceeded; the page shows a message and whole-word search keeps working.
- Plug-in tests: bad manifest, newer `api`, disabled plug-in, forged or expired signature, oversize response, a plug-in that proposes a write the user may not make.
- Security tests: prompt injection text in a PDF cannot cause a write; model output with markup is escaped.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Embedding cost or rate limits of the API | Paper-level embedding is small (title plus the first 2,000 characters); the admin sets a daily token budget and the job queue backs off on rate limits. |
| Vectors in PHP get slow at passage scale | Search is paper-level across the library and passage-level only inside one paper, so the number of vectors compared stays in the hundreds or low thousands. |
| Users send licensed text to a cloud provider without understanding it | Off by default, a plain notice, and a recorded acknowledgement per user (versioned). |
| Plug-in code reads everything | `http` runtime is recommended; `php` plug-ins are labelled as full trust. |
| Model quality is poor on this library | Phase 0 gate: no further work until known-item queries pass. |
| Cost surprises | Budgets, usage log, and a cost estimate shown before a full re-index. |

## 9. Decisions

Made on 2026-11-10

| # | Question | Decision |
|---|---|---|
| 1 | Where embeddings run | From an API, behind the provider interface (cloud first; any OpenAI-compatible server works too, including a local one). Text may leave `tars` once the user has allowed it with the per-user switch and acknowledged the notice. |
| 2 | Hardware and model servers | `tars` has an RTX 4070 (12 GB) and already runs a host-level Ollama and Open WebUI. **No model server is shipped with or inside the app image or its compose file.** The app only calls an API that the administrator points it at (section 10). |
| 3 | Language | English only for now. An English embedding model is enough. Multilingual support is a later switch of model, not a redesign. |
| 5 | Granularity | Paper-level search first. After a paper is opened, a person can search inside that paper (section 3.6). Library-wide passage search is left out. |
| 6 | Plug-in runtime | Separate services over signed HTTP are the main route. In-app PHP plug-ins are only for small trusted adapters. |

Decision 4 (PDF text and outside services) is settled in section 11.

## 10. Docker: options for the images

### 10.1 Principle: the app calls a model server, it does not contain one

Neither the app image nor `docker-compose.yml` includes Ollama, a model, or a GPU reservation. This keeps the image small and leaves the choice, the hardware and the model files to the administrator. The only AI-related thing the repository adds to Docker is the indexing worker (section 10.3), which is the same image as the app.

### 10.2 What `tars` already has (read-only check)

- GPU: NVIDIA RTX 4070, 12 GB, driver 615.78.
- A **host-level Ollama 0.40.15** (system service, `OLLAMA_HOST=0.0.0.0:11434`) with `qwen3:14b` and `qwen2.5-coder:14b`.
- **Open WebUI 0.10.2** (the `cuda` image) on port 3000. It requires an API key (an unauthenticated call returns 401).
- I could not confirm from outside which API paths Open WebUI offers (its `/openapi.json` returned a web page, not a specification). The paths it is documented to offer, and whether it offers embeddings at all, are checked in Phase 0 by the app's "Test connection" (section 10.4), not assumed.

### 10.3 What the app needs to be told

Compose adds only environment settings and the worker. The default `docker compose up -d` is unchanged.

```yaml
services:
  app:
    # ... as today ...
    environment:
      - OP_AI_ENABLED=${OP_AI_ENABLED:-0}
      - OP_AI_ALLOW_EXTERNAL=${OP_AI_ALLOW_EXTERNAL:-0}
    extra_hosts:
      - "host.docker.internal:host-gateway"    # reach a server on the Docker host

  worker:                                      # indexing; same image, only with  --profile ai
    profiles: ["ai"]
    image: offprintless:project-access
    command: ["php", "/i-librarian/bin/worker.php"]
    volumes: *same-volumes-as-app
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

The model servers themselves are entered on the administrator's AI page (or as environment variables, which win), once for each of two roles:

| Role | Settings | Example on `tars` |
|---|---|---|
| Embeddings | base URL, API key, model name | Ollama at `http://host.docker.internal:11434`, `nomic-embed-text` |
| Chat | base URL, API key, model name | Open WebUI at `http://host.docker.internal:3000/api`, `qwen3:14b`; or Ollama directly |

They are separate on purpose. Embeddings can use Ollama directly (small, fast) while chat goes through Open WebUI, and either may be a cloud service. Keys are never shown after saving, and the `IL_API_*` style environment variables override stored ones.

Any server that speaks the OpenAI-style API works with one adapter: Ollama (`/v1`), Open WebUI, LM Studio, vLLM, llama.cpp's server, and cloud services. Anthropic's chat API has its own small adapter.

Usage

- No AI: `docker compose up -d` (as today).
- AI against servers you already run: set `OP_AI_ENABLED=1`, then `docker compose --profile ai up -d`.
- Someone with no model server runs Ollama however they like (a package, or the official image on their own) and gives the app its URL. The documentation will show this in a short example, but the repository does not ship it.

### 10.4 "Test connection" (the AI admin page)

For each role it checks, and reports in plain language: the address is reachable; the key is accepted; the model name exists (it lists the server's models); and, for embeddings, a one-sentence test returns a vector, which also reveals the number of dimensions. A server that does not offer embeddings (which may be true of an Open WebUI gateway) is reported as "this server has no embeddings endpoint; use another server for embeddings", and chat can still be configured. The app records the dimension, because the index is built for one model and one dimension.

### 10.5 Networking and safety

- The model server address is administrator configuration. It may be a private address; that is the one place the app is allowed to call one, and the guarded downloader that user URLs go through must not be used for it.
- The existing host Ollama listens on every interface. That is how a container can reach it, but it also means any machine that can reach `tars` on port 11434 can use the GPU. The containers on `tars` use the bridge (`172.17.0.1`) and the `cloudflare-tunnel` network (`172.19.0.1`). The host firewall should allow 11434 only from the Docker networks and the local network. I could not read the firewall (it needs sudo), so this is for you to check.
- Models are not baked into any image. They live wherever the model server keeps them (on `tars`, `/var/lib/ollama`).

### 10.6 Models that fit a 12 GB card

| Job | Suggested model (English) | Memory |
|---|---|---|
| Embeddings | `nomic-embed-text` (768 dimensions) or `mxbai-embed-large` (1024) | under 1 GB |
| Chat | `qwen3:14b` (already on `tars`) | about 9 GB, leaving room for the embedder |

Both fit at once. Phase 0 compares these with a cloud embedding model on real papers before one is chosen. A chat model and an embedding model loaded together can be evicted by Open WebUI if it loads a large model, so the app must handle "model not loaded yet" with a retry and a clear message.

### 10.7 Other image build options

| Option | Values | Note |
|---|---|---|
| `TIMEZONE` | any tz name | exists |
| `EXTRA_CA_PEM` | PEM text | exists (TLS-inspecting networks) |
| `CADDY_VERSION` | e.g. `2.10.2` | exists |
| `WITH_OCR` | `0` / `1` | installs tesseract; off by default, as in the design notes |
| `WITH_OFFICE` | `0` / `1` | installs LibreOffice for Office import. The current image installs it unconditionally; making it a build option shrinks the default image by several hundred MB |
| `OP_AI_*` | see above | runtime settings, not build settings |

I would make the app image and the worker the same image, so there is one build to keep current.

## 11. Decision 4: PDF text and outside services (settled)

Decided: embeddings come from an API, and text may leave `tars` once a user has allowed it through the per-user switch.

What is sent. For semantic search, the paper's title and the opening of its text (about 2,000 characters). For chat, the passages that answer the question.

Why the notice exists. Publishers license journal content to a subscriber, and some forbid giving it to AI services. The application cannot know which applies. So each user is told, in plain words, that accepting allows their PDF text to be sent to the outside service, and must acknowledge it. The acknowledgement is stored with the notice version and the time.

What the design does.

1. **Off until allowed.** Nothing is sent for a user who has not turned the switch on and acknowledged the current notice.
2. **Administrator master switch.** The administrator can turn AI features off for the server, and configures the API and keys.
3. **Consent follows the sender.** A paper is sent only because a consenting user asked for it. A paper visible only to users who have not consented is never sent. Search and chat results are shown only to consenting users, and only for papers they can see.
4. **No quiet fallback.** If the API fails, the job waits and retries; text is never rerouted to another service.
5. **Withdrawing.** A user can switch off at any time; their requests stop. Vectors already built stay (they are deleted with the article, section 4 rule 8).

Dropped from earlier drafts: the "allow text to leave this server" switch and the per-project "never send to outside AI" mark. If a project owner later wants an exclusion mark, it can be added without changing the rest.

## 12. Searching inside a paper (decision 5)

After a paper is opened, the page gets a search box for that paper only.

- **Words:** the whole-word search over that paper's PDF text, with the same syntax as the library search, and the matches shown with their page numbers. This needs no AI and needs no index beyond the PDF text already stored.
- **Meaning:** the same box, in a "Meaning" mode, finds the passages that best answer the question. The paper is split into passages when it is first searched, embedded once, and kept. This is also what chat with a paper uses, so the work is shared.
- A hit shows the passage, the page, and a link that opens the PDF viewer on that page with the passage highlighted.
- Access is the same as for opening the paper: someone who cannot see the item cannot search inside it, and the passages are never returned in library-wide results.
- A paper that has no text (a scan) says so, and offers OCR if it is installed.

Library-wide search by passage (about 94,000 passages for the current library) is therefore not built. It can be added later without changing anything above, because the passages are stored the same way.
