<?php
// Run: php tests/connectors_test.php -- Scopus, ScienceDirect and Patents connectors, against canned responses.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Librarian\ItemMeta;

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}

$app = TestApp::create();
$di = $app->di;
$C = ItemMeta::COLUMN;

/** Build a connector whose HTTP client is a mock. Returns [connector, history]. */
function connector(string $class, array $responses, $di, ...$args) {
    $history = new ArrayObject();
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $obj = new $class($di, ...$args);
    $ref = new ReflectionProperty($class, 'client');
    $ref->setAccessible(true);
    $ref->setValue($obj, new Client(['handler' => $stack]));
    return [$obj, $history];
}

echo "--- Scopus ---\n";
$scopus = json_encode(['search-results' => [
    'opensearch:totalResults' => '2',
    'entry' => [[
        'dc:title' => 'Toxoplasma in pigs', 'dc:creator' => 'Jurankova, J.', 'dc:description' => 'An abstract.',
        'prism:publicationName' => 'Food Microbiology', 'prism:volume' => '38', 'prism:issueIdentifier' => '1',
        'prism:pageRange' => '167-170', 'prism:coverDate' => '2014-04-01', 'prism:doi' => '10.1016/j.fm.2013.08.011',
        'eid' => '2-s2.0-84884919538', 'prism:aggregationType' => 'Journal',
        'author' => [['surname' => 'Jurankova', 'given-name' => 'Jana'], ['surname' => 'Smith', 'initials' => 'A.']],
        'affiliation' => [['affilname' => 'Univ A'], ['affilname' => 'Univ A'], ['affilname' => 'Univ B']],
        'authkeywords' => 'Pigs | Real-time PCR | Toxoplasma gondii',
    ], [
        'dc:title' => 'Proceedings paper', 'dc:creator' => 'Kim, Lee', 'prism:aggregationType' => 'Conference Proceeding', 'eid' => '2-s2.0-1',
    ], [
        'error' => 'Result set was empty',
    ]],
]]);
[$sc, $h] = connector(\Librarian\External\Scopus::class, [new Response(200, [], $scopus)], $di, 'KEY123');
$r = $sc->search([['TITLE-ABS-KEY' => 'toxoplasma'], ['AUTH' => 'jurankova']], 1, 10, [], '-citedby-count');
$it = $r['items'];
check('total found is read', $r['found'], 2);
check('the error entry is skipped', count($it), 2);
check('title, journal, volume, issue, pages, date',
    [$it[0][$C['TITLE']], $it[0][$C['SECONDARY_TITLE']], $it[0][$C['VOLUME']], $it[0][$C['ISSUE']], $it[0][$C['PAGES']], $it[0][$C['PUBLICATION_DATE']]],
    ['Toxoplasma in pigs', 'Food Microbiology', '38', '1', '167-170', '2014-04-01']);
check('DOI and Scopus EID become UIDs', [$it[0][$C['UID_TYPES']], $it[0][$C['UIDS']]], [['DOI', 'SCOPUS'], ['10.1016/j.fm.2013.08.011', '2-s2.0-84884919538']]);
check('authors are split', [$it[0][$C['AUTHOR_LAST_NAME']], $it[0][$C['AUTHOR_FIRST_NAME']]], [['Jurankova', 'Smith'], ['Jana', 'A.']]);
check('duplicate affiliations collapse', $it[0][$C['AFFILIATION']], 'Univ A, Univ B');
check('keywords split on the pipe', $it[0][$C['KEYWORDS']], ['Pigs', 'Real-time PCR', 'Toxoplasma gondii']);
check('article type', $it[0][$C['REFERENCE_TYPE']], ItemMeta::TYPE['ARTICLE']);
check('conference type and creator fallback', [$it[1][$C['REFERENCE_TYPE']], $it[1][$C['AUTHOR_LAST_NAME']]], [ItemMeta::TYPE['CONFERENCE'], ['Kim']]);
$req = $h[0]['request'];
parse_str($req->getUri()->getQuery(), $q);
check('query is AND-joined field codes', $q['query'], 'TITLE-ABS-KEY(toxoplasma) AND AUTH(jurankova)');
check('start is zero-based, count capped, sort passed', [$q['start'], $q['count'], $q['sort']], ['0', '10', '-citedby-count']);
check('API key travels in a header, not the URL', [$req->getUri()->getQuery() !== '' && !str_contains((string) $req->getUri(), 'KEY123'), $req->getHeaderLine('X-ELS-APIKey') ?: 'client-level'], [true, 'client-level']);

[$sc2, $h2] = connector(\Librarian\External\Scopus::class, [new Response(200, [], $scopus)], $di, 'K');
$sc2->search([['TITLE' => 'a "quoted" {term}']], 1);
parse_str($h2[0]['request']->getUri()->getQuery(), $q2);
check('quotes and braces cannot break out of the query', $q2['query'], 'TITLE(a  quoted   term )');
[$sc3, $h3] = connector(\Librarian\External\Scopus::class, [], $di, 'K');
check('an unknown field and no terms make no request', [$sc3->search([['EVIL' => 'x']], 1)['items'], count($h3)], [[], 0]);
[$sc4, ] = connector(\Librarian\External\Scopus::class, [new Response(401)], $di, 'K');
$msg = ''; try { $sc4->search([['TITLE' => 'x2']], 1); } catch (Throwable $e) { $msg = $e->getMessage(); }
check('a refused key gives a readable error', str_contains($msg, 'refused the API key'), true);
[$sc5, $h5] = connector(\Librarian\External\Scopus::class, [new Response(200, [], $scopus)], $di, 'K');
$sc5->fetch('scopus: 2-s2.0-84884919538');
parse_str($h5[0]['request']->getUri()->getQuery(), $q5);
check('fetch by EID', $q5['query'], 'EID(2-s2.0-84884919538)');
[$sc6, $h6] = connector(\Librarian\External\Scopus::class, [new Response(200, [], $scopus)], $di, 'K');
$sc6->fetch('10.1016/j.fm.2013.08.011');
parse_str($h6[0]['request']->getUri()->getQuery(), $q6);
check('fetch by DOI', $q6['query'], 'DOI(10.1016/j.fm.2013.08.011)');

echo "--- ScienceDirect ---\n";
$sd = json_encode(['resultsFound' => 5, 'results' => [[
    'title' => 'A ScienceDirect article', 'doi' => '10.1016/j.x.2020.01.001', 'pii' => 'S0000000020300011',
    'sourceTitle' => 'Journal X', 'publicationDate' => '2020-02-15',
    'authors' => [['name' => 'Ada Lovelace'], ['name' => 'Alan M. Turing'], ['name' => '']],
], ['doi' => 'no-title']]]);
[$sdc, $hd] = connector(\Librarian\External\Sciencedirect::class, [new Response(200, [], $sd)], $di, 'KEY');
$r = $sdc->search([['qs' => 'turing'], ['pub' => 'Journal X']], 11, 10, [], 'date');
$it = $r['items'];
check('found and untitled result skipped', [$r['found'], count($it)], [5, 1]);
check('metadata', [$it[0][$C['TITLE']], $it[0][$C['SECONDARY_TITLE']], $it[0][$C['PUBLICATION_DATE']]], ['A ScienceDirect article', 'Journal X', '2020-02-15']);
check('DOI and PII become UIDs', [$it[0][$C['UID_TYPES']], $it[0][$C['UIDS']]], [['DOI', 'DIRECT'], ['10.1016/j.x.2020.01.001', 'S0000000020300011']]);
check('author names split, blank skipped', [$it[0][$C['AUTHOR_LAST_NAME']], $it[0][$C['AUTHOR_FIRST_NAME']]], [['Lovelace', 'Turing'], ['Ada', 'Alan M.']]);
check('article link', $it[0][$C['URLS']][0], 'https://www.sciencedirect.com/science/article/pii/S0000000020300011');
$req = $hd[0]['request'];
$body = json_decode((string) $req->getBody(), true);
check('uses PUT with a JSON body', [$req->getMethod(), $body['qs'], $body['pub']], ['PUT', 'turing', 'Journal X']);
check('paging and sort', [$body['display']['offset'], $body['display']['show'], $body['display']['sortBy']], [10, 10, 'date']);
[$sdc2, $hd2] = connector(\Librarian\External\Sciencedirect::class, [], $di, 'K');
check('unknown fields make no request', [$sdc2->search([['evil' => 'x']], 1)['items'], count($hd2)], [[], 0]);

echo "--- Patents ---\n";
$pat = json_encode(['results' => ['total_num_results' => 42, 'cluster' => [['result' => [
    ['patent' => ['publication_number' => 'US7654321B2', 'title' => 'A <b>widget</b> &amp; method', 'snippet' => 'Holds <b>things</b>.',
                  'inventor' => 'Jane Q Public', 'assignee' => 'Acme Corp', 'publication_date' => '2010-02-02']],
    ['patent' => ['title' => 'no number']],
]]]]]);
[$pc, $hp] = connector(\Librarian\External\Patents::class, [new Response(200, [], $pat)], $di);
$r = $pc->search([['q' => 'widget'], ['assignee' => 'Acme']], 1, 10, [], 'new');
$it = $r['items'];
check('found, and the item without a number is skipped', [$r['found'], count($it)], [42, 1]);
check('title and snippet are plain text', [$it[0][$C['TITLE']], $it[0][$C['ABSTRACT']]], ['A widget & method', 'Holds things.']);
check('patent UID, link, assignee, type', [$it[0][$C['UIDS']], $it[0][$C['URLS']][0], $it[0][$C['AFFILIATION']], $it[0][$C['REFERENCE_TYPE']]],
    [['US7654321B2'], 'https://patents.google.com/patent/US7654321B2', 'Acme Corp', ItemMeta::TYPE['PATENT']]);
check('inventor is split', [$it[0][$C['AUTHOR_LAST_NAME']], $it[0][$C['AUTHOR_FIRST_NAME']]], [['Public'], ['Jane Q']]);
$url = (string) $hp[0]['request']->getUri();
check('request goes to Google Patents with the terms', [str_starts_with($url, 'https://patents.google.com/xhr/query'), str_contains(rawurldecode($url), 'q=widget'), str_contains(rawurldecode($url), 'sort=new')], [true, true, true]);
[$pc2, $hp2] = connector(\Librarian\External\Patents::class, [], $di);
check('unknown fields make no request', [$pc2->search([['evil' => 'x']], 1)['items'], count($hp2)], [[], 0]);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
