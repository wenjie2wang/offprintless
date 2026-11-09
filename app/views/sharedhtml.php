<?php

namespace LibrarianApp;

use Exception;
use Librarian\AppSettings;
use Librarian\Container\DependencyInjector;
use \Librarian\Html\Bootstrap;
use Librarian\ItemMeta;
use Librarian\Media\Temporal;
use Librarian\Security\Validation;

/**
 * Trait with reusable static HTML elements.
 */
trait SharedHtmlView {

    /**
     * @var DependencyInjector
     */
    protected DependencyInjector $di;

    /**
     * @var Validation
     */
    protected $validation;

    /**
     * A static HTML element: quick search form.
     *
     * @param string|null $action
     * @return string
     * @throws Exception
     */
    public function sharedQuickSearch(string $action = null): string {

        /** @var Bootstrap\IconButton $el */
        $el = $this->di->get('IconButton');

        $el->type('submit');
        $el->context('primary');
        $el->style('border: 0;padding: 0 10px');
        $el->icon('magnify');
        $search_button = $el->render();

        $el = null;

        /** @var Bootstrap\Inputgroup $el */
        $el = $this->di->get('InputGroup');

        $el->name('search_query[]');
        $el->ariaLabel('Search query');
        $el->placeholder($this->lang->t9n('Search query'));
        $el->required('required');
        $el->appendButton($search_button);
        $search_input = $el->render();

        $el = null;

        /** @var Bootstrap\Input $el */
        $el = $this->di->get('Input');

        $el->type('radio');
        $el->inline(true);
        $el->id('search_boolean_and');
        $el->name('search_boolean[]');
        $el->value('AND');
        $el->checked('checked');
        $el->label(
<<<HTML
<span class="text-uppercase">{$this->lang->t9n('and')}</span>
HTML
        );
        $search_boolean = $el->render();

        $el = null;

        /** @var Bootstrap\Input $el */
        $el = $this->di->get('Input');

        $el->type('radio');
        $el->inline(true);
        $el->id('search_boolean_or');
        $el->name('search_boolean[]');
        $el->value('OR');
        $el->label(
<<<HTML
<span class="text-uppercase">{$this->lang->t9n('or')}</span>
HTML
        );
        $search_boolean .= $el->render();

        $el = null;

        /** @var Bootstrap\Input $el */
        $el = $this->di->get('Input');

        $el->type('radio');
        $el->inline(true);
        $el->id('search_boolean_phrase');
        $el->name('search_boolean[]');
        $el->value('PHRASE');
        $el->label(
<<<HTML
<span class="text-uppercase">{$this->lang->t9n('phrase')}</span>
HTML
        );
        $search_boolean .= $el->render();

        $el = null;

        /** @var Bootstrap\Select $el */
        $el = $this->di->get('Select');

        $el->addClass('mt-2');
        $el->name('search_type[]');
        $el->ariaLabel('Search in');
        $el->option("{$this->lang->t9n('Metadata')} + PDFs", 'anywhere');
        $el->option($this->lang->t9n('Metadata'), 'metadata');
        $el->option('PDFs', 'FT');
        $el->option($this->lang->t9n('PDF notes'), 'pdfnotes');
        $el->option($this->lang->t9n('Notes'), 'itemnotes');
        $el->option("UID (DOI, PMID,&hellip;)", 'uid');
        $el->option("{$this->lang->t9n('Item')} ID", 'itemid');
        $search_type = $el->render();

        $el = null;

        /** @var Bootstrap\Input $el */
        $el = $this->di->get('Input');

        $el->type('checkbox');
        $el->inline(true);
        $el->id('save-search-quick');
        $el->name('save_search');
        $el->value('1');
        $el->label($this->lang->t9n('save this search for later'));
        $save_search = $el->render();

        $el = null;

        /** @var Bootstrap\Form $el */
        $el = $this->di->get('Form');

        $el->id('quick-search-form');
        $el->method('GET');
        $el->action(isset($action) ? $action : '#items/main');
        $help = '<small class="form-text text-muted mb-2">' .
            $this->lang->t9n('Whole words. Use * for a beginning or end (estim*), "quotes" for a phrase, NOT or - to leave a word out.') .
            '</small>';

        $el->append("$search_input $help $search_boolean $search_type $save_search");
        $quick_search_form = $el->render();

        $el = null;

        return $quick_search_form;
    }

    /**
     * @param array $tags
     * @param string|null $action
     * @return string
     * @throws Exception
     */
    public function sharedAdvancedSearch(array $tags, string $action = null): string {

        $rows = '';

        for ($i = 0; $i < 2; $i++) {

            /** @var Bootstrap\Select $el */
            $el = $this->di->get('Select');

            $el->id('input-type-' . $i);
            $el->groupClass('my-2');
            $el->addClass('fields');
            $el->name("search_type[{$i}]");
            $el->ariaLabel('Search in');
            $el->option($this->lang->t9n('Title'), 'TI');
            $el->option("{$this->lang->t9n('Title')} {$this->lang->t9n('or')} {$this->lang->t9n('abstract')}", 'AB', $i === 0);
            $el->option("PDF {$this->lang->t9n('fulltext')}", 'FT');
            $el->option("{$this->lang->t9n('Author')} {$this->lang->t9n('or')} {$this->lang->t9n('editor')}", 'AU', $i === 1);
            $el->option($this->lang->t9n('Affiliation'), 'AF');
            $el->option($this->lang->t9n('Primary title'), 'T1');
            $el->option($this->lang->t9n('Secondary title'), 'T2');
            $el->option($this->lang->t9n('Tertiary title'), 'T3');
            $el->option($this->lang->t9n('Keyword'), 'KW');
            $el->option($this->lang->t9n('Year'), 'YR');

            /** @var AppSettings $app_settings */
            $app_settings = $this->app_settings;

            for ($j = 1; $j <= 8; $j++) {

                $el->option($app_settings->getGlobal('custom' . $j), "C{$j}");
            }

            $search_type = $el->render();

            $el = null;

            /** @var Bootstrap\Input $el */
            $el = $this->di->get('Input');

            $el->id('input-query-' . $i);
            $el->groupClass('my-2');
            $el->name("search_query[{$i}]");
            $el->ariaLabel('Search query');
            $el->placeholder($this->lang->t9n('Search query'));
            $search_input = $el->render();

            $el = null;

            /** @var Bootstrap\Input $el */
            $el = $this->di->get('Input');

            $el->id('input-and-' . $i);
            $el->type('radio');
            $el->inline(true);
            $el->name("search_boolean[$i]");
            $el->value('AND');
            $el->checked('checked');
            $el->label($this->lang->t9n('AND'));
            $search_boolean = $el->render();

            $el = null;

            /** @var Bootstrap\Input $el */
            $el = $this->di->get('Input');

            $el->id('input-or-' . $i);
            $el->type('radio');
            $el->inline(true);
            $el->name("search_boolean[$i]");
            $el->value('OR');
            $el->label($this->lang->t9n('OR'));
            $search_boolean .= $el->render();

            $el = null;

            /** @var Bootstrap\Input $el */
            $el = $this->di->get('Input');

            $el->id('input-phrase-' . $i);
            $el->type('radio');
            $el->inline(true);
            $el->name("search_boolean[$i]");
            $el->value('PHRASE');
            $el->label($this->lang->t9n('PHRASE'));
            $search_boolean .= $el->render();

            $el = null;

            /** @var Bootstrap\Select $el */
            $el = $this->di->get('Select');

            $el->id('input-glue-' . $i);
            $el->groupClass('my-2');
            $el->style('width: 5rem');
            $el->name("search_glue[{$i}]");
            $el->ariaLabel('AND/OR/NOT');
            $el->option($this->lang->t9n('AND'), 'AND');
            $el->option($this->lang->t9n('OR'), 'OR');
            $el->option($this->lang->t9n('NOT'), 'NOT');
            $glue = $el->render();

            $el = null;

            if ($i === 0) {

                $glue = '<div style="width: 5rem"></div>';
            }

            /** @var Bootstrap\Row $el */
            $el = $this->di->get('Row');

            if ($i === 1) {

                $el->id('clone-target');
            }

            $el->addClass('no-gutters');
            $el->column($glue, 'col-lg-auto pr-1');
            $el->column($search_type, 'col-lg-3 pr-1');
            $el->column($search_input . $search_boolean, 'col-lg pr-1');

            $rows .= $el->render();

            $el = null;
        }

        /** @var Bootstrap\IconButton $el */
        $el = $this->di->get('IconButton');

        $el->addClass('clone-button btn-round mb-3');
        $el->context('primary');
        $el->icon('plus');
        $clone_rows = $el->render();

        $el = null;

        /** @var Bootstrap\IconButton $el */
        $el = $this->di->get('IconButton');

        $el->addClass('remove-clone-button btn-round ml-2 mb-3');
        $el->context('secondary');
        $el->icon('minus');
        $clone_rows .= $el->render();

        $el = null;

        // Tags.

        /** @var Bootstrap\Icon $el */
        $el = $this->di->get('Icon');

        $el->icon('chevron-down');
        $arrow = $el->render();

        $el = null;

        $tag_html = "<div class=\"my-3 cursor-pointer\" data-toggle=\"collapse\" data-target=\"#search-tags\">$arrow<b>{$this->lang->t9n('Tags')}</b></div>";

        // Filter input.
        /** @var Bootstrap\Input $el */
        $el = $this->di->get('Input');

        $el->id('tag-filter-search');
        $el->placeholder($this->lang->t9n('Filter-NOUN'));
        $el->ariaLabel($this->lang->t9n('Filter-NOUN'));
        $el->attr('data-targets', '#search-tags .label-text');
        $tag_filter = $el->render();

        $el = null;

        // First letter.
        $first_letter = '';
        $i = 0;

        $tag_checkboxes = '<table class="tag-table"><tr><td style="width:2.25rem"></td><td>';

        foreach ($tags as $tag_id => $tag) {

            $first_letter2 = mb_strtoupper($this->scalar_utils->deaccent($tag[0] === '' ? '' : mb_substr($tag, 0, 1, 'UTF-8')), 'UTF-8');

            if ($first_letter2 !== $first_letter) {

                $tag_checkboxes .= '</td></tr><tr>';

                /** @var Bootstrap\Badge $el */
                $el = $this->di->get('Badge');

                $el->context('warning');
                $el->addClass('d-inline-block mr-2 mb-2');
                $el->style('width: 1.33rem');
                $el->html($first_letter2);
                $tag_checkboxes .= '<td>' . $el->render() . '</td><td>';

                $el = null;

                $first_letter = $first_letter2;
            }

            /** @var Bootstrap\Input $el */
            $el = $this->di->get('Input');

            $el->groupClass('tag-divs');
            $el->addClass('tag-inputs');
            $el->id('search-checkbox-tags-' . $i);
            $el->type('checkbox');
            $el->name("search_filter[tag][{$i}]");
            $el->value($tag_id);
            $el->label($tag);
            $el->inline(true);

            $tag_checkboxes .= $el->render();

            $el = null;

            $i++;
        }

        $tag_checkboxes .= '</td></tr></table>';

        /** @var Bootstrap\Card $el */
        $el = $this->di->get('Element');

        $el->id('search-tags');
        $el->addClass('mb-3 collapse');
        $el->html($tag_filter . $tag_checkboxes);
        $tag_html .= $el->render();

        $el = null;

        /** @var Bootstrap\Input $el */
        $el = $this->di->get('Input');

        $el->type('checkbox');
        $el->id('save-search-advanced');
        $el->name('save_search');
        $el->value('1');
        $el->label($this->lang->t9n('save this search for later'));
        $save_search = $el->render();

        $el = null;

        /** @var Bootstrap\IconButton $el */
        $el = $this->di->get('Button');

        $el->type('submit');
        $el->context('primary');
        $el->style('position: fixed; top: 0;left: -500px');
        $el->html('Submit');
        $submit = $el->render();

        $el = null;

        /** @var Bootstrap\Form $el */
        $el = $this->di->get('Form');

        $el->id('advanced-search-form');
        $el->method('GET');
        $el->action(isset($action) ? $action : '#items/main');
        $el->html("$rows $clone_rows $tag_html $save_search $submit");
        $quick_search_form = $el->render();

        $el = null;

        return $quick_search_form;
    }

    /**
     * File upload.
     *
     * @param bool $multiple
     * @param string|null $web_link
     * @return string
     * @throws Exception
     */
    public function sharedFileInput(bool $multiple = false, string $web_link = null): string {

        // File select button.
        $affix = $multiple === true ? 's' : '';

        /** @var Bootstrap\Button $el */
        $el = $this->di->get('Button');

        $el->addClass('uploadable-select mt-2');
        $el->context('secondary');
        $el->html(
<<<HTML
+ {$this->lang->t9n('Select local file' . $affix)}
HTML
        );
        $file_button = $el->render();

        $el = null;

        /** @var Bootstrap\Button $el */
        $el = $this->di->get('Button');

        $el->addClass('uploadable-clear mt-2 d-none');
        $el->context('danger');
        $el->html($this->lang->t9n('Clear'));
        $clear_button = $el->render();

        $el = null;

        /** @var Bootstrap\Inputgroup $el File input. */
        $el = $this->di->get('Input');

        $el->id('file-' . uniqid());
        $el->type('file');
        $el->addClass('uploadable-file d-none');
        $el->name('file');

        if ($multiple === true) {

            $el->attr('multiple', 'multiple');
        }

        $file_input  = $el->render();

        $el = null;

        /** @var Bootstrap\ProgressBar $el */
        $el = $this->di->get('ProgressBar');

        $el->addClass('uploadable-progress bg-darker-5');
        $el->style('height: 4px');
        $el->value(1);
        $progress = $el->render();

        $el = null;

        /** @var Bootstrap\ListGroup $el File list container. */
        $el = $this->di->get('ListGroup');

        $el->addClass('uploadable-list d-none mb-3');
        $el->div($progress, 'p-0');
        $el->div('<div class="p-3" style="max-height: 33vh;overflow: auto"></div>', 'p-0');
        $list = $el->render();

        $el = null;

        // Remove invalid link.
        if (!empty($web_link)) {

            try {

                $this->validation->ssrfLink($web_link);

            } catch (Exception $exc) {

                $web_link = '';
            }
        }

        /** @var Bootstrap\Input $el Remote URL input. */
        $el = $this->di->get('Input');

        $el->id('uploadable-url-' . uniqid());
        $el->name('remote_url');
        $el->value($web_link);
        $el->addClass('uploadable-url');
        $el->label($this->lang->t9n('Upload file from a URL'));
        $url_input = $el->render();

        return "$file_button $clear_button $file_input $list $url_input";
    }

    /**
     * List of saved searches.
     *
     * @param array $searches
     * @param bool $external
     * @return string
     * @throws Exception
     */
    public function sharedSearchList(array $searches, bool $external = false): string {

        /** @var Bootstrap\ListGroup $el List group of saved searches. */
        $el = $this->di->get('ListGroup');

        $el->id('search-list');

        if (empty($searches)) {

            $el->div(
<<<HTML
<small>{$this->lang->t9n('No saved searches')}</small>
HTML
            , 'py-3 text-secondary text-uppercase');
        }

        // Saved searches.
        foreach ($searches as $search) {

            $edit = '';

            if ($external === true) {

                /** @var Bootstrap\Button $btn Delete saved search btn.*/
                $btn = $this->di->get('Button');

                $btn->addClass('edit-search my-1');
                $btn->context('outline-primary');
                $btn->componentSize('small');
                $btn->style('min-width: 4rem');
                $btn->html($this->lang->t9n('Edit'));
                $edit = $btn->render();

                $btn = null;
            }

            /** @var Bootstrap\Button $btn Delete saved search btn.*/
            $btn = $this->di->get('Button');

            $btn->addClass('delete-search my-1');
            $btn->context('outline-danger');
            $btn->componentSize('small');
            $btn->html($this->lang->t9n('Delete'));
            $btn->attr('data-id', $search['id']);
            $btn->attr('data-url', IL_BASE_URL . 'index.php/search/delete');
            $delete = $btn->render();

            $btn = null;

            /** @var Temporal $temporal */
            $temporal = $this->di->getShared('Temporal');
            $last_search = $temporal->toUserTime($search['changed_time']);
            $diff = $temporal->diff($search['changed_time']);

            $searches_html =
<<<HTML
<div class="mr-3">
    <a href="{$search['search_url']}">{$search['search_name']}</a><br>
    {$this->lang->t9n('Last search')}: {$last_search} <span class="text-muted">&mdash; {$diff}</span>
</div>
<div style="white-space: nowrap">
    $edit
    $delete
</div>
HTML;

            $el->div($searches_html, 'd-flex justify-content-between align-items-center border-0');
        }

        $list = $el->render();

        $el = null;

        return $list;
    }

    /**
     * Generate a list of URL links from item metadata.
     *
     * @param array $item
     * @return string
     */
    public function sharedLinkList(array $item): string {

        // Links.
        $links = '';

        // External UIDs.
        if (!empty($item[ItemMeta::COLUMN['UID_TYPES']])) {

            foreach ($item[ItemMeta::COLUMN['UID_TYPES']] as $key => $type) {

                $value = $item[ItemMeta::COLUMN['UIDS']][$key];

                switch ($type) {

                    case 'DOI':
                        $doi_lmth = $this->sanitation->lmth($value);
                        $doi_url = $this->sanitation->urlquery($doi_lmth);
                        $doi_attr = $this->sanitation->attr($doi_url);
                        $links .= empty($doi_url) ? "" : "<a class=\"mr-3\" href=\"https://dx.doi.org/{$doi_attr}\">{$this->lang->t9n('Publisher')}</a>";
                        break;

                    case 'PMID':
                        $name = 'Pubmed';
                        $value_lmth = $this->sanitation->lmth($value);
                        $value_url = $this->sanitation->urlquery($value_lmth);
                        $value_html = $this->sanitation->attr($value_url);
                        $href = 'https://www.ncbi.nlm.nih.gov/pubmed/' . $value_html;
                        $links .= "<a class=\"mr-3\" href=\"$href\">$name</a> ";
                        $href = 'https://www.ncbi.nlm.nih.gov/pubmed?linkname=pubmed_pubmed&from_uid=' . $value_html;
                        $links .= "<a class=\"mr-3\" href=\"$href\">{$this->lang->t9n('Similar')}</a> ";
                        $href = 'https://www.ncbi.nlm.nih.gov/pubmed?linkname=pubmed_pubmed_citedin&from_uid=' . $value_html;
                        $links .= "<a class=\"mr-3\" href=\"$href\">{$this->lang->t9n('Cited in')}</a> ";
                        break;

                    case 'PMCID':
                        $name = 'PMC';
                        $value_lmth = $this->sanitation->lmth($value);
                        $value_url = $this->sanitation->urlquery($value_lmth);
                        $value_html = $this->sanitation->attr($value_url);
                        $href = 'https://www.ncbi.nlm.nih.gov/pmc/' . $value_html;
                        $links .= "<a class=\"mr-3\" href=\"$href\">$name</a> ";
                        break;
                }
            }
        }

        foreach ($item['urls'] as $url) {

            if (empty($url)) {

                continue;
            }

            $links .= "<a class=\"mr-3\" href=\"$url\">" . parse_url($url, PHP_URL_HOST) . "</a> ";
        }

        // I, Librarian stable link.
        $IL_BASE_URL = IL_BASE_URL;
        $links .= "<a class=\"mr-3\" href=\"{$IL_BASE_URL}stable.php?id={$item['id']}\">{$this->lang->t9n('Stable link')}</a>";

        return $links;
    }

    /**
     * A simple external-database search page: rows of field + terms, optional sorting, save-search box, and the
     * list of saved searches.
     *
     * @param string $label Database name, e.g. "Scopus".
     * @param string $slug Route name, e.g. "scopus".
     * @param array $fields [field => label]
     * @param array $preselected Field for each of the term rows.
     * @param array $sorting [value => label]; the first is the default. Empty for none.
     * @param array $searches Saved searches.
     * @param string $note Optional HTML note above the form.
     * @return string
     * @throws Exception
     */
    protected function simpleSearchPage(
        string $label,
        string $slug,
        array $fields,
        array $preselected,
        array $sorting,
        array $searches,
        string $note = ''
    ): string {

        $this->title("$label {$this->lang->t9n('search-NOUN')}");

        $this->head();

        /** @var Bootstrap\Breadcrumb $el */
        $el = $this->di->get('Breadcrumb');

        $el->style('margin: 0 -15px');
        $el->addClass('bg-transparent');
        $el->item('IL', '#dashboard');
        $el->item("$label " . $this->lang->t9n('search-NOUN'));
        $bc = $el->render();

        $el = null;

        $rows = '';

        foreach ($preselected as $i => $selected_field) {

            /** @var Bootstrap\Select $el */
            $el = $this->di->get('Select');

            $el->groupClass('col-sm-3');
            $el->name("search_type[$i]");
            $el->label($this->lang->t9n('Field'));
            $el->id("parameter-" . ($i + 1));

            foreach ($fields as $field => $description) {

                $el->option($this->lang->t9n($description), $field, $field === $selected_field);
            }

            $select = $el->render();

            $el = null;

            /** @var Bootstrap\Input $el */
            $el = $this->di->get('Input');

            $el->id('value-' . ($i + 1));
            $el->groupClass('col-sm-9');
            $el->name("search_query[$i]");
            $el->label($this->lang->t9n('Terms'));
            $input = $el->render();

            $el = null;

            $rows .= "<div class=\"form-row\">$select $input</div>";
        }

        $sort_html = '';

        if ($sorting !== []) {

            $n = 1;

            foreach ($sorting as $value => $description) {

                /** @var Bootstrap\Input $el */
                $el = $this->di->get('Input');

                $el->type('radio');
                $el->id("sorting-$n");
                $el->name('sort');
                $el->inline(true);
                $el->value($value);
                $el->label($this->lang->t9n($description));

                if ($n === 1) {

                    $el->checked('checked');
                }

                $sort_html .= $el->render();

                $el = null;
                $n++;
            }

            $sort_html = "<div class=\"mb-3\"><b>{$this->lang->t9n('Sorting')}</b><br>$sort_html</div>";
        }

        /** @var Bootstrap\Button $el */
        $el = $this->di->get('Button');

        $el->type('submit');
        $el->addClass('my-2 mr-3');
        $el->context('primary');
        $el->html($this->lang->t9n('Search-VERB'));
        $search = $el->render();

        $el = null;

        /** @var Bootstrap\Input $el */
        $el = $this->di->get('Input');

        $el->type('checkbox');
        $el->inline(true);
        $el->id('save-search');
        $el->name('save_search');
        $el->value('1');
        $el->label($this->lang->t9n('save this search for later'));
        $save_search = $el->render();

        $el = null;

        /** @var Bootstrap\Form $el */
        $el = $this->di->get('Form');

        $el->method('GET');
        $el->id("$slug-search-form");
        $el->addClass('search-form');
        $el->action("#$slug/search");
        $el->html("$note $rows $sort_html $search $save_search");
        $form = $el->render();

        $el = null;

        /** @var Bootstrap\Card $el */
        $el = $this->di->get('Card');

        $el->addClass('mb-3');
        $el->body($form, null, 'pt-3');
        $card = $el->render();

        $el = null;

        $list = $this->sharedSearchList($searches, true);

        /** @var Bootstrap\Row $el */
        $el = $this->di->get('Row');

        $el->addClass('mb-3');
        $el->column($card);
        $el->column($list);
        $row = $el->render();

        $el = null;

        $this->append(['html' => $bc . $row]);

        return $this->send();
    }
}
