<?php

namespace LibrarianApp;

use Exception;
use Librarian\Html\Bootstrap;
use Librarian\Mvc\TextView;

/**
 * Chat with one paper. The page is a shell; messages are filled in by the script (history and streaming), always as
 * text, never as HTML, so nothing a model or a PDF says can add markup.
 */
class ChatView extends TextView {

    /**
     * @param int $item_id
     * @param string $title
     * @param int $window Context window of the chat model, for the meter.
     * @return string
     * @throws Exception
     */
    public function main(int $item_id, string $title, int $window): string {

        $this->title("{$this->lang->t9n('Chat')} - {$title}");

        $this->head();

        /** @var Bootstrap\Breadcrumb $el */
        $el = $this->di->get('Breadcrumb');

        $el->style('margin: 0 -15px');
        $el->addClass('bg-transparent');
        $el->item($this->lang->t9n('Home'), IL_BASE_URL . 'index.php/#dashboard/main');
        $el->item($title, '#summary?id=' . $item_id);
        $el->item($this->lang->t9n('Chat'));
        $bc = $el->render();

        $el = null;

        $t = fn(string $s) => htmlspecialchars($this->lang->t9n($s), ENT_QUOTES);
        $placeholder = $t('Ask a question about this paper');
        $send = $t('Send');
        $note = $t('Answers are written by an AI model from passages of this paper. Check them against the PDF. The passages are sent to the AI service set up by the administrator.');
        $meter_label = $t('Context used');
        $empty = $t('This paper has no chat yet.');

        $html = <<<EOT
<div id="chat-root" data-item-id="{$item_id}" data-window="{$window}">
    <div class="text-muted small mb-2">{$note}</div>
    <div class="small mb-2"><span class="text-muted">{$meter_label}:</span> <span id="chat-meter">0 / {$window}</span>
        <div class="progress" style="height:4px"><div id="chat-meter-bar" class="progress-bar" style="width:0%"></div></div></div>
    <div id="chat-log" class="mb-3" aria-live="polite"><div class="text-muted" id="chat-empty">{$empty}</div></div>
    <form id="chat-form">
        <textarea class="form-control mb-2" id="chat-question" name="question" rows="2" maxlength="2000" placeholder="{$placeholder}"></textarea>
        <button type="submit" class="btn btn-danger" id="chat-send">{$send}</button>
        <span class="small text-danger ml-2 ms-2" id="chat-error"></span>
    </form>
</div>
EOT;

        $this->append(['html' => "$bc $html"]);

        return $this->send();
    }
}
