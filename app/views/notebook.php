<?php

namespace LibrarianApp;

use Exception;
use Librarian\Html\Bootstrap;
use Librarian\Mvc\TextView;

/**
 * The notebook page is a shell; sources and messages are filled in by the script, always as text.
 */
class NotebookView extends TextView {

    /**
     * @param int $project_id
     * @param string $title
     * @param int $window Context window of the chat model, for the meter.
     * @return string
     * @throws Exception
     */
    public function main(int $project_id, string $title, int $window): string {

        $this->title("{$this->lang->t9n('Notebook')} - {$title}");

        $this->head();

        /** @var Bootstrap\Breadcrumb $el */
        $el = $this->di->get('Breadcrumb');

        $el->style('margin: 0 -15px');
        $el->addClass('bg-transparent');
        $el->item('IL', IL_BASE_URL . 'index.php/#dashboard/main');
        $el->item($title, '#project/browse?id=' . $project_id);
        $el->item($this->lang->t9n('Notebook'));
        $bc = $el->render();

        $el = null;

        $t = fn(string $s) => htmlspecialchars($this->lang->t9n($s), ENT_QUOTES);
        $note = $t('Answers are written by an AI model from the papers you tick. Check them against the PDFs. Text from the ticked papers is sent to the AI service set up by the administrator.');
        $sources = $t('Papers');
        $all = $t('All');
        $none = $t('None');
        $briefing = $t('Briefing');
        $compare = $t('Compare papers');
        $faq = $t('FAQ and glossary');
        $placeholder = $t('Ask a question about the ticked papers');
        $send = $t('Send');
        $meter_label = $t('Context used');
        $empty = $t('Nothing here yet. Tick papers, then ask a question or choose an output.');

        $html = <<<EOT
<div id="nb-root" data-project-id="{$project_id}" data-window="{$window}">
    <div class="text-muted small mb-2">{$note}</div>
    <div class="row">
        <div class="col-lg-4 mb-3">
            <div class="d-flex justify-content-between align-items-center"><b>{$sources}</b>
                <span class="small"><a href="#" id="nb-all">{$all}</a> / <a href="#" id="nb-none">{$none}</a></span></div>
            <div id="nb-sources" class="mt-2"></div>
            <div class="mt-3">
                <button type="button" class="btn btn-outline-secondary btn-sm mb-1 nb-output" data-mode="briefing">{$briefing}</button>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-1 nb-output" data-mode="compare">{$compare}</button>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-1 nb-output" data-mode="faq">{$faq}</button>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="small mb-2"><span class="text-muted">{$meter_label}:</span> <span id="nb-meter">0 / {$window}</span>
                <div class="progress" style="height:4px"><div id="nb-meter-bar" class="progress-bar" style="width:0%"></div></div></div>
            <div id="nb-status" class="small text-muted mb-2"></div>
            <div id="nb-log" class="mb-3" aria-live="polite"><div class="text-muted" id="nb-empty">{$empty}</div></div>
            <form id="nb-form">
                <textarea class="form-control mb-2" id="nb-question" rows="2" maxlength="2000" placeholder="{$placeholder}"></textarea>
                <button type="submit" class="btn btn-danger" id="nb-send">{$send}</button>
                <span class="small text-danger ml-2 ms-2" id="nb-error"></span>
            </form>
        </div>
    </div>
</div>
EOT;

        $this->append(['html' => "$bc $html"]);

        return $this->send();
    }
}
