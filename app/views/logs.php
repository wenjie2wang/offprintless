<?php

namespace LibrarianApp;

use Exception;
use Librarian\Html\Bootstrap;
use Librarian\Mvc\TextView;

class LogsView extends TextView {

    /**
     * @param array $report From LogsModel::report().
     * @return string
     * @throws Exception
     */
    public function main(array $report): string {

        $this->title($this->lang->t9n('Logs'));

        $this->head();

        /** @var Bootstrap\Breadcrumb $el */
        $el = $this->di->get('Breadcrumb');

        $el->style('margin: 0 -15px');
        $el->addClass('bg-transparent');
        $el->item($this->lang->t9n('Home'), '#dashboard');
        $el->item($this->lang->t9n('Logs'));
        $bc = $el->render();

        $el = null;

        $days = (int) $report['days'];
        $base = IL_BASE_URL . 'index.php#logs/main?days=';
        $links = [];

        foreach ([7 => '7 days', 14 => '14 days', 30 => '30 days'] as $d => $label) {

            $links[] = $d === $days ? "<b>{$this->lang->t9n($label)}</b>" : "<a href=\"{$base}{$d}\">{$this->lang->t9n($label)}</a>";
        }

        $window = '<p>' . $this->lang->t9n('Period') . ': ' . join(' &middot; ', $links) . '</p>'
            . '<p class="text-secondary small">' . $this->lang->t9n('Details are kept for 7 days, daily totals for 30 days.') . '</p>';

        // Per-user totals.
        /** @var Bootstrap\Table $el */
        $el = $this->di->get('Table');

        $el->addClass('table-sm');
        $table = $el->head([
            [$this->lang->t9n('User')],
            [$this->lang->t9n('Items opened')],
            [$this->lang->t9n('PDFs downloaded')],
            [$this->lang->t9n('Pages read')],
            [$this->lang->t9n('Edits')]
        ]);

        foreach ($report['users'] as $user) {

            $table .= $el->bodyRow([
                [$user['name']],
                [(int) $user['opens']],
                [(int) $user['downloads']],
                [(int) $user['pages']],
                [(int) $user['edits']]
            ]);
        }

        $users_table = $el->render();
        $el = null;

        /** @var Bootstrap\Card $el */
        $el = $this->di->get('Card');

        $el->addClass('mb-3');
        $el->header("<b>{$this->lang->t9n('Activity per user')}</b>", 'text-uppercase');
        $el->body($window . $users_table);
        $users_card = $el->render();
        $el = null;

        // Recent events.
        /** @var Bootstrap\Table $el */
        $el = $this->di->get('Table');

        $el->addClass('table-sm');
        $table = $el->head([
            [$this->lang->t9n('Time')],
            [$this->lang->t9n('User')],
            [$this->lang->t9n('Action')],
            [$this->lang->t9n('Item')]
        ]);

        foreach ($report['recent'] as $event) {

            $table .= $el->bodyRow([
                [$event['created']],
                [$event['name']],
                [$this->lang->t9n($event['kind'])],
                [$event['title']]
            ]);
        }

        $recent_table = $el->render();
        $el = null;

        /** @var Bootstrap\Card $el */
        $el = $this->di->get('Card');

        $el->addClass('mb-3');
        $el->header("<b>{$this->lang->t9n('Recent activity')}</b>", 'text-uppercase');
        $el->body($recent_table);
        $recent_card = $el->render();
        $el = null;

        $this->append(['html' => $bc . $users_card . $recent_card]);

        return $this->send();
    }
}
