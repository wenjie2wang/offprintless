PRAGMA page_size = 4096;
PRAGMA user_version = 1;
PRAGMA secure_delete = OFF;
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;

/* Passages (kind = chunk) and paper vectors (kind = paper). Everything here can be rebuilt from the PDFs. vec is
   base64 of little-endian float32. */
CREATE TABLE IF NOT EXISTS vectors(
    id        INTEGER PRIMARY KEY,
    item_id   INTEGER NOT NULL,
    kind      TEXT    NOT NULL,
    chunk_no  INTEGER NOT NULL,
    page      INTEGER NOT NULL DEFAULT 0,
    model_id  TEXT    NOT NULL,
    dims      INTEGER NOT NULL,
    vec       TEXT    NOT NULL,
    text      TEXT    NOT NULL DEFAULT '',
    text_hash TEXT    NOT NULL DEFAULT '',
    created   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_vectors_item ON vectors(item_id, kind, model_id);

/* Chats are private to their user. scope is item or project; scope_id the item or project id. */
CREATE TABLE IF NOT EXISTS chats(
    id       INTEGER PRIMARY KEY,
    user_id  INTEGER NOT NULL,
    scope    TEXT    NOT NULL,
    scope_id INTEGER NOT NULL,
    created  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_chats_user ON chats(user_id, scope, scope_id);

CREATE TABLE IF NOT EXISTS chat_messages(
    id        INTEGER PRIMARY KEY,
    chat_id   INTEGER NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
    role      TEXT    NOT NULL,
    content   TEXT    NOT NULL,
    citations TEXT    NOT NULL DEFAULT '[]',
    kind      TEXT    NOT NULL DEFAULT 'ask',
    sources   TEXT    NOT NULL DEFAULT '[]',
    tokens    INTEGER NOT NULL DEFAULT 0,
    created   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_chat_messages_chat ON chat_messages(chat_id);

/* Tokens used per user and day, for the daily budget. */
CREATE TABLE IF NOT EXISTS usage(
    user_id INTEGER NOT NULL,
    day     TEXT    NOT NULL,
    tokens  INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(user_id, day)
);
