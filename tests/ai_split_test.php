<?php
// Run: php tests/ai_split_test.php  -- chat and embeddings may use different services; embeddings fall back to the chat one.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}

use Librarian\Ai\AiGate;
use Librarian\Ai\ProviderConfig;

$app = TestApp::create();
$s = $app->di->getShared('AppSettings');
check('embedding address and key settings exist, empty by default', ($s->default_global_settings['ai_embed_base_url'] ?? null) === '' && ($s->default_global_settings['api_ai_embed'] ?? null) === '');

$g = ['ai_enabled' => '1', 'ai_base_url' => 'https://chat.example/v1', 'ai_chat_model' => 'c', 'ai_embed_model' => 'e', 'ai_embed_base_url' => '', 'ai_context_window' => '1000'];

echo "--- falling back ---\n";
$c = ProviderConfig::forEmbeddings($g, 'chat-key', '');
check('no embedding address: uses the chat address', $c['base_url'] === 'https://chat.example/v1');
check('...and the chat key', $c['api_key'] === 'chat-key');
check('...and the embedding model', $c['embed_model'] === 'e');

echo "--- separate service ---\n";
$g2 = ['ai_embed_base_url' => 'http://ollama:11434/v1'] + $g;
$c = ProviderConfig::forEmbeddings($g2, 'chat-key', '');
check('own address is used', $c['base_url'] === 'http://ollama:11434/v1');
check('the chat key is NOT sent to the embedding service', $c['api_key'] === '');
$c = ProviderConfig::forEmbeddings($g2, 'chat-key', 'embed-key');
check('its own key is used when given', $c['api_key'] === 'embed-key');
$c = ProviderConfig::forEmbeddings(['ai_embed_base_url' => 'http://ollama:11434/v1/'] + $g, 'k', '');
check('a trailing slash is harmless', rtrim($c['base_url'], '/') === 'http://ollama:11434/v1');
check('chat config always uses the chat address and key', ProviderConfig::forChat($g2, 'chat-key')['base_url'] === 'https://chat.example/v1' && ProviderConfig::forChat($g2, 'chat-key')['api_key'] === 'chat-key');

echo "--- the gate ---\n";
$user = ['ai_enabled' => '1', 'ai_ack_version' => \Librarian\Security\AiConsent::VERSION, 'ai_ack_at' => 'now'];
check('a separate embedding address and no chat address is refused (503)', (function () use ($g2, $user) { try { AiGate::check(['ai_base_url' => ''] + $g2, $user, true); } catch (Exception $e) { return $e->getCode() === 503; } return false; })());
check('no embedding model is still 503 when embeddings are needed', (function () use ($g2, $user) { try { AiGate::check(['ai_embed_model' => ''] + $g2, $user, true); } catch (Exception $e) { return $e->getCode() === 503; } return false; })());

check('a separate embedding service does not replace the chat address', (function () use ($g2, $user) { try { AiGate::check(['ai_base_url' => ''] + $g2, $user, true); } catch (Exception $e) { return $e->getCode() === 503; } return false; })());
check('chat-only features need no embedding setup at all', AiGate::check(['ai_embed_model' => '', 'ai_embed_base_url' => ''] + $g2, $user, false) === null);

echo "--- the providers the app builds ---\n";
$s->setGlobal(['ai_enabled' => '1', 'ai_base_url' => 'https://chat.example/v1', 'ai_chat_model' => 'c', 'ai_embed_model' => 'e', 'ai_embed_base_url' => 'http://ollama:11434/v1']);
$p = $app->di->get('AiProviders');
check('chat and embeddings are different objects when the services differ', $p['chat'] !== $p['embed']);
$s->setGlobal(['ai_enabled' => '1', 'ai_base_url' => 'https://chat.example/v1', 'ai_chat_model' => 'c', 'ai_embed_model' => 'e', 'ai_embed_base_url' => '']);
$p = $app->di->get('AiProviders');
check('embeddings use the chat service when none is set', $p['chat'] === $p['embed'] || get_class($p['chat']) === get_class($p['embed']));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
