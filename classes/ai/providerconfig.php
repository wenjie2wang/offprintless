<?php

namespace Librarian\Ai;

/**
 * Which service, key and model each part of the AI features talks to.
 *
 * Chat and embeddings may be different services: Anthropic has no embeddings, so Claude can answer while another
 * service (a local Ollama, for instance) embeds. With no separate embedding address, both use the chat service.
 *
 * A key is never sent to a service it was not given for: the chat key is not used for a separate embedding service.
 */
final class ProviderConfig {

    /**
     * @param array $g Global settings.
     * @param string $key The chat service key.
     * @return array For OpenAiCompatible: base_url, api_key, chat_model, embed_model, context_window.
     */
    public static function forChat(array $g, string $key): array {

        return [
            'base_url'       => (string) ($g['ai_base_url'] ?? ''),
            'api_key'        => $key,
            'chat_model'     => (string) ($g['ai_chat_model'] ?? ''),
            'embed_model'    => '',
            'context_window' => (int) ($g['ai_context_window'] ?? 8000)
        ];
    }

    /**
     * @param array $g Global settings.
     * @param string $chat_key The chat service key.
     * @param string $embed_key The key for the embedding service; used only when that service has its own address.
     */
    public static function forEmbeddings(array $g, string $chat_key, string $embed_key): array {

        $own = trim((string) ($g['ai_embed_base_url'] ?? '')) !== '';

        return [
            'base_url'       => $own ? trim((string) $g['ai_embed_base_url']) : (string) ($g['ai_base_url'] ?? ''),
            'api_key'        => $own ? $embed_key : $chat_key,
            'chat_model'     => '',
            'embed_model'    => (string) ($g['ai_embed_model'] ?? ''),
            'query_prefix'   => (string) ($g['ai_embed_query_prefix'] ?? ''),
            'doc_prefix'     => (string) ($g['ai_embed_doc_prefix'] ?? ''),
            'context_window' => (int) ($g['ai_context_window'] ?? 8000)
        ];
    }

    /**
     * Whether embeddings go to a different service than chat.
     */
    public static function separate(array $g): bool {

        return trim((string) ($g['ai_embed_base_url'] ?? '')) !== '';
    }
}
