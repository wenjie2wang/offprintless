<?php
// Run: php tests/import_reliability_test.php -- identifiers, DOI-from-PDF, provider errors, odd uploads.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }
function msg(callable $fn): string { try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); } }

$app = TestApp::create();
$di = $app->di;
$C = Librarian\ItemMeta::COLUMN;
$wire = function ($obj, array $responses) { $h = new ArrayObject(); $s = HandlerStack::create(new MockHandler($responses)); $s->push(Middleware::history($h)); $p = new ReflectionProperty(get_class($obj), 'client'); $p->setAccessible(true); $p->setValue($obj, new Client(['handler' => $s])); return $h; };

echo "=== Identifiers typed or pasted by a person ===\n";
$id = fn(string $s) => Librarian\Import\Identifier::parse($s);
check('a bare DOI', $id('10.1000/xyz123'), ['DOI', '10.1000/xyz123']);
check('DOI with doi: prefix and spaces', $id('  doi: 10.1000/xyz123 '), ['DOI', '10.1000/xyz123']);
check('DOI as a doi.org link', $id('https://doi.org/10.1000/xyz123'), ['DOI', '10.1000/xyz123']);
check('DOI as a dx.doi.org link', $id('http://dx.doi.org/10.1000/xyz123'), ['DOI', '10.1000/xyz123']);
check('DOI with trailing punctuation from a sentence', $id('10.1000/xyz123.'), ['DOI', '10.1000/xyz123']);
check('DOI with a slash in the suffix', $id('10.1016/S0140-6736(20)30183-5/fulltext'), ['DOI', '10.1016/S0140-6736(20)30183-5/fulltext']);
check('arXiv: new-style number', $id('1706.03762'), ['ARXIV', '1706.03762']);
check('arXiv: with version', $id('1706.03762v7'), ['ARXIV', '1706.03762v7']);
check('arXiv: with prefix', $id('arXiv:1706.03762v7'), ['ARXIV', '1706.03762v7']);
check('arXiv: abs link', $id('https://arxiv.org/abs/1706.03762'), ['ARXIV', '1706.03762']);
check('arXiv: pdf link', $id('https://arxiv.org/pdf/1706.03762v7.pdf'), ['ARXIV', '1706.03762v7']);
check('arXiv: old-style', $id('hep-th/9901001'), ['ARXIV', 'hep-th/9901001']);
check('arXiv: old-style with prefix', $id('arXiv:math.GT/0309136'), ['ARXIV', 'math.GT/0309136']);
check('PubMed id with prefix', $id('PMID: 12345678'), ['PMID', '12345678']);
check('PubMed link', $id('https://pubmed.ncbi.nlm.nih.gov/12345678/'), ['PMID', '12345678']);
check('PMC id', $id('PMC1234567'), ['PMCID', 'PMC1234567']);
check('ISBN-13 with hyphens', $id('ISBN 978-3-16-148410-0'), ['ISBN', '9783161484100']);
check('ISBN-10', $id('0-306-40615-2'), ['ISBN', '0306406152']);
check('a bare number is not guessed to be anything', $id('12345678'), [null, '12345678']);
check('nothing recognizable', $id('hello world'), [null, 'hello world']);
check('empty', $id('   '), [null, '']);

echo "=== DOI from the text of a PDF ===\n";
$doi_of = fn(array $lines) => Librarian\Import\Identifier::doiFromPdfText(implode("\n", $lines));
check('first DOI of a normal article', $doi_of(['Nature volume 579', 'https://doi.org/10.1038/s41586-020-0001-1', 'Received: 1 Jan']), '10.1038/s41586-020-0001-1');
check('"doi:" prefix', $doi_of(['doi: 10.1038/nature12373']), '10.1038/nature12373');
check('trailing period, comma, semicolon', $doi_of(['DOI 10.1038/nature12373.']), '10.1038/nature12373');
check('in parentheses', $doi_of(['(doi:10.1038/nature12373)']), '10.1038/nature12373');
check('in brackets', $doi_of(['[10.1038/nature12373]']), '10.1038/nature12373');
check('a DOI whose suffix has a slash', $doi_of(['10.1016/S0140-6736(20)30183-5/fulltext']), '10.1016/S0140-6736(20)30183-5/fulltext');
check('a DOI with balanced parentheses keeps them', $doi_of(['10.1002/(SICI)1097-0258(19970415)16:7<751::AID-SIM505>3.0.CO;2-4']), '10.1002/(SICI)1097-0258(19970415)16:7<751::AID-SIM505>3.0.CO;2-4');
check('page ranges like 2010.1234/5 are not DOIs', $doi_of(['pages 2010.1234/5']), null);
check('an ISBN is not a DOI', $doi_of(['ISBN 978-3-16-148410-0']), null);
check('no DOI at all', $doi_of(['Just some text', 'on two lines']), null);
check('the DOI on the first page wins over a cited DOI later', $doi_of(['Title of the paper', 'doi:10.2222/this.paper', "\f", 'References', '1. Smith. doi:10.1111/old.paper']), '10.2222/this.paper');
check('the DOI printed next to the word "doi" wins over a bare cited one before it', $doi_of(['Cited as 10.1111/earlier.1', 'This paper: doi:10.2222/new.2']), '10.2222/new.2');
check('a DOI split across a line break with a trailing hyphen is rejoined', $doi_of(['doi:10.1038/s41586-020-', '0001-1 more text']), '10.1038/s41586-020-0001-1');
check('only the first pages are searched (a bibliography DOI is not the paper)', $doi_of(array_merge(array_fill(0, 3, "\f"), ['References', 'doi:10.9999/cited'])), null);
check('unicode hyphens in the suffix are normalized', $doi_of(["doi:10.1000/ab\u{2010}cd"]), '10.1000/ab-cd');

echo "=== Crossref: errors are not 'not found' ===\n";
$cr = function (array $responses) use ($di, $wire) { $c = new Librarian\External\Crossref($di, 'me@example.org'); $h = $wire($c, $responses); return [$c, $h]; };
$work = fn(array $extra = []) => new Response(200, [], json_encode(['message-type' => 'work-list', 'message' => ['total-results' => 1, 'items' => [array_merge(['DOI' => '10.1/x', 'type' => 'journal-article', 'title' => ['A title']], $extra)]]]));
[$c] = $cr([new Response(200, [], json_encode(['message-type' => 'work-list', 'message' => ['total-results' => 0, 'items' => []]]))]);
check('a DOI Crossref does not know is "not found" (empty result)', $c->fetch('10.1/none'), ['found' => 0, 'items' => []]);
[$c] = $cr([new Response(404)]);
check('HTTP 404 is also "not found"', $c->fetch('10.1/none')['found'], 0);
[$c] = $cr([new Response(500)]);
check('HTTP 500 is an error that says the server failed', [code(fn() => $c->fetch('10.1/x')), str_contains(msg(fn() => (function () use ($cr) { [$c2] = $cr([new Response(500)]); $c2->fetch('10.1/x'); })()), 'Crossref')], [502, true]);
[$c] = $cr([new Response(503), new Response(503), new Response(503), new Response(503)]);
check('HTTP 503 that does not go away is reported as busy (503)', code(fn() => $c->fetch('10.1/x')), 503);
[$c] = $cr([new ConnectException('timeout', new Request('GET', 'x'))]);
check('a network failure is a readable error', [code(fn() => $c->fetch('10.1/x')), str_contains(msg(function () use ($cr) { [$c2] = $cr([new ConnectException('timeout', new Request('GET', 'x'))]); $c2->fetch('10.1/x'); }), 'could not be reached')], [502, true]);
[$c] = $cr([new Response(200, [], 'not json at all')]);
check('a non-JSON answer is a readable error, not an exception from the JSON library', [code(fn() => $c->fetch('10.1/x')), str_contains(msg(function () use ($cr) { [$c2] = $cr([new Response(200, [], '<html>maintenance</html>')]); $c2->fetch('10.1/x'); }), 'Crossref')], [502, true]);
[$c] = $cr([new Response(429), new Response(429), new Response(429), new Response(429)]);
check('429 is "busy, try again later" after the retries', msg(fn() => $c->fetch('10.1/x')), 'Crossref server is busy, try again later');

echo "=== Crossref: the data ===\n";
[$c] = $cr([$work(['issued' => ['date-parts' => [[2020, 3]]]])]);
check('the "issued" date is used when there is no print or online date', $c->fetch('10.1/x')['items'][0][$C['PUBLICATION_DATE']], '2020-03-01');
[$c] = $cr([$work(['published-print' => ['date-parts' => [[2019, 12, 5]]], 'issued' => ['date-parts' => [[2020, 3]]]])]);
check('print date wins over issued', $c->fetch('10.1/x')['items'][0][$C['PUBLICATION_DATE']], '2019-12-05');
[$c] = $cr([$work(['issued' => ['date-parts' => [[null]]]])]);
check('an empty date is no date, not "-01-01"', $c->fetch('10.1/x')['items'][0][$C['PUBLICATION_DATE']] ?? null, null);
[$c] = $cr([$work(['title' => ['Cats <i>and</i> dogs &amp; <sub>2</sub>x &lt; y']])]);
check('HTML and entities in a title become plain text', $c->fetch('10.1/x')['items'][0][$C['TITLE']], 'Cats and dogs & 2x < y');
[$c] = $cr([$work(['abstract' => '<jats:p>An <jats:italic>abstract</jats:italic> here.</jats:p><jats:p>Second.</jats:p>'])]);
check('JATS markup in an abstract becomes plain text', $c->fetch('10.1/x')['items'][0][$C['ABSTRACT']] ?? null, 'An abstract here. Second.');
[$c] = $cr([$work(['author' => [['family' => 'Smith', 'given' => 'John'], ['name' => 'Consortium X'], ['given' => 'Only'], ['family' => 'Lee']]])]);
$a = $c->fetch('10.1/x')['items'][0];
check('authors: family+given, an organization, a given name alone, a family name alone', array_map(null, $a[$C['AUTHOR_LAST_NAME']], $a[$C['AUTHOR_FIRST_NAME']]), [['Smith', 'John'], ['Consortium X', ''], ['Only', ''], ['Lee', '']]);
[$c] = $cr([$work(['title' => ['Crossref title'], 'DOI' => '10.1234/ABC.def'])]);
$a = $c->fetch('10.1/x')['items'][0];
check('the DOI is stored as Crossref gives it', [$a[$C['UID_TYPES']], $a[$C['UIDS']]], [['DOI'], ['10.1234/ABC.def']]);
[$c] = $cr([$work(['title' => []])]);
check('a record with no title is left out, not saved blank', $c->fetch('10.1/x')['items'], []);
[$c, $h] = $cr([$work()]);
$c->fetch('10.1002/(SICI)1097-0258(19970415)16:7<751::AID-SIM505>3.0.CO;2-4');
check('a DOI with brackets and angle brackets is sent whole, as one filter', urldecode((string) $h[0]['request']->getUri()), 'https://api.crossref.org/works?filter=doi:10.1002/(SICI)1097-0258(19970415)16:7<751::AID-SIM505>3.0.CO;2-4&mailto=me@example.org');
[$c, $h] = $cr([$work()]);
$c->fetch('10.1000/a,b');
check('a comma in a DOI cannot split the filter in two: the DOI is looked up by its own address', urldecode((string) $h[0]['request']->getUri()), 'https://api.crossref.org/works/10.1000/a,b?mailto=me@example.org');

echo "=== arXiv ===\n";
$feed = fn(array $entries) => '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:opensearch="http://a9.com/-/spec/opensearch/1.1/" xmlns:arxiv="http://arxiv.org/schemas/atom"><opensearch:totalResults>' . count($entries) . '</opensearch:totalResults>' . implode('', $entries) . '</feed>';
$entry = fn(string $n = '1706.03762v7') => "<entry><id>http://arxiv.org/abs/$n</id><title>Attention Is All\n   You Need</title><summary> Abstract. </summary><published>2017-06-12T17:57:34Z</published><author><name>Ashish Vaswani</name></author></entry>";
$ax = function (array $responses) use ($di, $wire) { $a = new Librarian\External\Arxiv($di); $h = $wire($a, $responses); return [$a, $h]; };
[$a, $h] = $ax([new Response(200, [], $feed([$entry()]))]);
$a->fetch('arXiv:1706.03762v7');
parse_str($h[0]['request']->getUri()->getQuery(), $q);
check('arXiv: a prefixed id is sent as the bare id', $q['id_list'], '1706.03762v7');
[$a, $h] = $ax([new Response(200, [], $feed([$entry()]))]);
$a->fetch('https://arxiv.org/abs/1706.03762');
parse_str($h[0]['request']->getUri()->getQuery(), $q);
check('arXiv: a pasted link is sent as the bare id', $q['id_list'], '1706.03762');
[$a, $h] = $ax([new Response(200, [], $feed([$entry('hep-th/9901001')]))]);
$a->fetch('hep-th/9901001');
parse_str($h[0]['request']->getUri()->getQuery(), $q);
check('arXiv: an old-style id keeps its slash', $q['id_list'], 'hep-th/9901001');
[$a] = $ax([new Response(200, [], $feed([$entry()]))]);
$r = $a->fetch('1706.03763');
check('arXiv: the title is on one line', $r['items'][0][$C['TITLE']], 'Attention Is All You Need');
[$a] = $ax([new Response(200, [], '<html>Down for maintenance</html>')]);
check('arXiv: an HTML maintenance page is an error, not "not found"', [code(fn() => $a->fetch('2001.00001')), str_contains(msg(function () use ($ax) { [$a2] = $ax([new Response(200, [], '<html>Down</html>')]); $a2->fetch('2001.00001'); }), 'arXiv')], [502, true]);
[$a] = $ax([new Response(200, [], '')]);
check('arXiv: an empty answer has a message', str_contains(msg(function () use ($ax) { [$a2] = $ax([new Response(200, [], '')]); $a2->fetch('2001.00003'); }), 'arXiv'), true);
check('arXiv: HTTP 503 that does not go away is reported as busy', msg(function () use ($ax) { [$a2] = $ax(array_fill(0, 5, new Response(503))); $a2->fetch('2001.00004'); }), 'arXiv server is busy, try again later');
check('arXiv: HTTP 500 is a server error', msg(function () use ($ax) { [$a2] = $ax([new Response(500)]); $a2->fetch('2001.00006'); }), 'arXiv server error');
check('arXiv: a 503 that clears on the second try succeeds', (function () use ($ax, $feed, $entry) { [$a2] = $ax([new Response(503), new Response(200, [], $feed([$entry('2001.00007')]))]); return $a2->fetch('2001.00007')['found']; })(), 1);
[$a] = $ax([new Response(200, [], $feed([]))]);
check('arXiv: an id that does not exist is "not found" (empty result)', $a->fetch('2001.00005')['found'], 0);

echo "=== PubMed and PMC use the same error rules ===\n";
$pm = new Librarian\External\Pubmed($di, '');
$wire($pm, [new Response(500)]);
check('PubMed HTTP 500 is a readable error', str_contains(msg(fn() => $pm->fetch('9990001')), 'Pubmed'), true);
$pm = new Librarian\External\Pubmed($di, '');
$wire($pm, [new Response(200, [], '<html>maintenance</html>')]);
check('PubMed returning HTML is a readable error', str_contains(msg(fn() => $pm->fetch('9990002')), 'Pubmed'), true);

$pm_xml = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE PubmedArticleSet PUBLIC "-//NLM//DTD PubMedArticle, 1st January 2024//EN" "https://dtd.nlm.nih.gov/ncbi/pubmed/out/pubmed_240101.dtd"><PubmedArticleSet><PubmedArticle><MedlineCitation><PMID Version="1">9990003</PMID><Article><Journal><ISSN>0000-0000</ISSN><JournalIssue><Volume>5</Volume><Issue>2</Issue><PubDate><Year>2020</Year></PubDate></JournalIssue><Title>Journal of Tests</Title></Journal><ArticleTitle>A real-shaped PubMed record.</ArticleTitle><AuthorList><Author><LastName>Smith</LastName><ForeName>John</ForeName></Author></AuthorList></Article></MedlineCitation><PubmedData><ArticleIdList><ArticleId IdType="pubmed">9990003</ArticleId></ArticleIdList></PubmedData></PubmedArticle></PubmedArticleSet>';
$pm = new Librarian\External\Pubmed($di, ''); $wire($pm, [new Response(200, [], $pm_xml)]);
$r = $pm->fetch('9990003');
check('PubMed: a real-shaped answer is read', [count($r['items']), $r['items'][0][$C['TITLE']] ?? null], [1, 'A real-shaped PubMed record.']);
$pm = new Librarian\External\Pubmed($di, ''); $wire($pm, [new Response(200, [], '<?xml version="1.0"?><PubmedArticleSet></PubmedArticleSet>')]);
check('PubMed: an empty article set is "no results", not an error', $pm->fetch('9990004')['items'], []);
$pm = new Librarian\External\Pubmed($di, ''); $wire($pm, [new Response(404)]);
check('PubMed: HTTP 404 is "no results"', $pm->fetch('9990005')['items'], []);
$pm = new Librarian\External\Pubmed($di, ''); $h = $wire($pm, [new Response(429), new Response(200, [], $pm_xml)]);
check('PubMed: a 429 that clears is retried and succeeds', count($pm->fetch('9990006')['items']), 1);
$pmc = new Librarian\External\Pmc($di, ''); $wire($pmc, [new Response(200, [], '<html>maintenance</html>')]);
check('PMC: an HTML answer is a readable error', str_contains(msg(fn() => $pmc->fetch('PMC9990001')), 'Pubmed Central'), true);
$pmc = new Librarian\External\Pmc($di, ''); $wire($pmc, [new Response(200, [], '<?xml version="1.0"?><pmc-articleset></pmc-articleset>')]);
check('PMC: an empty article set is "no results"', $pmc->fetch('PMC9990002')['items'], []);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
