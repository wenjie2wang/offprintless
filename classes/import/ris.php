<?php

namespace Librarian\Import;

use Exception;
use Librarian\ItemMeta;

/**
 * RIS importer.
 *
 * A record starts at a "TY  - " line and ends at "ER  - " (or at the next TY, or the end of the text). Every other
 * line is "XX  - value", where XX is two characters. A line that does not look like a tag continues the previous
 * value, which is how long abstracts and titles are wrapped.
 */
class Ris {

    /**
     * @var string[] Records, each the text of one TY ... ER block.
     */
    private array $records = [];

    /**
     * @var ItemMeta
     */
    private ItemMeta $item_meta;

    /**
     * @var array Records that were read but not usable.
     */
    private array $skipped = [];

    /**
     * RIS types the application has no exact column for, mapped to one it has.
     */
    private const TYPE_ALIASES = [
        'EJOUR' => 'JOUR', 'JFULL' => 'JOUR', 'MGZN' => 'JOUR', 'NEWS' => 'JOUR', 'ABST' => 'JOUR', 'INPR' => 'JOUR',
        'CPAPER' => 'CONF', 'CONF' => 'CONF', 'ECHAP' => 'CHAP', 'EBOOK' => 'BOOK', 'EDBOOK' => 'BOOK',
        'THES' => 'THES', 'RPRT' => 'RPRT', 'SER' => 'BOOK', 'GEN' => 'GEN', 'WEB' => 'ELEC', 'BLOG' => 'ELEC',
        'COMP' => 'ELEC', 'DATA' => 'DATA', 'DBASE' => 'DATA', 'ELEC' => 'ELEC', 'PAT' => 'PAT', 'STAND' => 'STAND',
        'UNPB' => 'UNPB', 'BOOK' => 'BOOK', 'CHAP' => 'CHAP', 'JOUR' => 'JOUR', 'MANSCPT' => 'UNPB', 'PCOMM' => 'GEN',
    ];

    public function __construct(ItemMeta $item_meta, string $input) {

        $this->item_meta = $item_meta;
        $this->records = $this->splitRecords(ImportUtils::toUtf8($input));
    }

    /**
     * @return array
     */
    public function getSkipped(): array {

        return $this->skipped;
    }

    /**
     * Cut the text into records, ignoring anything before the first TY and anything after each ER.
     *
     * @param string $text
     * @return string[]
     */
    private function splitRecords(string $text): array {

        $records = [];
        $current = null;

        foreach (explode("\n", $text) as $line) {

            if (preg_match('/^TY {1,2}-(?: |$)/', $line) === 1) {

                // A new TY closes a record that was never closed with ER.
                if ($current !== null) {

                    $records[] = $current;
                }

                $current = $line;
                continue;
            }

            if ($current === null) {

                continue;
            }

            if (preg_match('/^ER {1,2}-(?: |$)/', $line) === 1) {

                $records[] = $current;
                $current = null;
                continue;
            }

            $current .= "\n" . $line;
        }

        if ($current !== null) {

            $records[] = $current;
        }

        return $records;
    }

    /**
     * Entry iterator. Returns the next usable record, or null when the input is used up.
     *
     * @return array|null
     * @throws Exception
     */
    public function getEntry() {

        while (($text = array_shift($this->records)) !== null) {

            $entry = $this->build($text);

            if ($entry !== null) {

                return $entry;
            }
        }

        return null;
    }

    /**
     * Split a record into [tag, value] pairs. A line that is not a tag continues the previous value.
     *
     * @param string $text
     * @return array
     */
    private function tags(string $text): array {

        $pairs = [];

        foreach (explode("\n", $text) as $line) {

            // "XX  - value" (two spaces, the standard) or "XX - value" (one space, used by some exporters).
            if (preg_match('/^([A-Z][A-Z0-9]) {1,2}-(?: (.*))?$/u', $line, $m) === 1) {

                $pairs[] = [$m[1], trim($m[2] ?? '')];

            } elseif (preg_match('/^PMID ?-\s*(\d+)\s*$/', $line, $m) === 1) {

                // PubMed writes "PMID- 12345678" (and some tools "PMID - 12345678"). It is its own tag, not a
                // continuation of the line before it.
                $pairs[] = ['PMID', $m[1]];

            } elseif ($pairs !== [] && trim($line) !== '') {

                $last = count($pairs) - 1;
                $pairs[$last][1] = trim($pairs[$last][1] . ' ' . trim($line));
            }
        }

        return $pairs;
    }

    /**
     * @param string $text One record.
     * @return array|null
     */
    private function build(string $text): ?array {

        $C = ItemMeta::COLUMN;
        $entry = [];
        $pairs = $this->tags($text);

        $first = [];
        $day = null;
        $py = null;
        $da = null;
        $sp = null;
        $ep = null;
        $uids = [];
        $key = null;
        $type = 'GEN';

        $add_uid = function (string $uid_type, ?string $value) use (&$uids): void {

            if ($value !== null && $value !== '' && in_array([$uid_type, $value], $uids, true) === false) {

                $uids[] = [$uid_type, $value];
            }
        };

        foreach ($pairs as [$tag, $value]) {

            if ($value === '') {

                continue;
            }

            switch ($tag) {

                case 'TY':
                    $type = strtoupper($value);
                    break;

                case 'ID':
                    $key = $value;
                    break;

                case 'TI':
                case 'T1':
                    $entry[$C['TITLE']] = $entry[$C['TITLE']] ?? $value;
                    break;

                case 'JA':
                case 'J2':
                    $entry[$C['PRIMARY_TITLE']] = $value;
                    break;

                case 'JF':
                case 'JO':
                case 'BT':
                case 'T2':
                    $entry[$C['SECONDARY_TITLE']] = $entry[$C['SECONDARY_TITLE']] ?? $value;
                    break;

                case 'T3':
                    $entry[$C['TERTIARY_TITLE']] = $value;
                    break;

                case 'PY':
                case 'Y1':
                    $py = $value;
                    break;

                case 'DA':
                    $da = $value;
                    break;

                case 'VL':
                    $entry[$C['VOLUME']] = $value;
                    break;

                case 'IS':
                    $entry[$C['ISSUE']] = $value;
                    break;

                case 'SP':
                    $sp = $value;
                    break;

                case 'EP':
                    $ep = $value;
                    break;

                case 'PB':
                    $entry[$C['PUBLISHER']] = $value;
                    break;

                case 'CY':
                    $entry[$C['PLACE_PUBLISHED']] = $value;
                    break;

                case 'AB':
                case 'N2':
                    $entry[$C['ABSTRACT']] = $entry[$C['ABSTRACT']] ?? $value;
                    break;

                case 'DO':
                    $add_uid('DOI', ImportUtils::doi($value));
                    break;

                case 'UR':
                    // A DOI link is an identifier; any link is also kept as a URL.
                    if (preg_match('~^https?://(dx\.)?doi\.org/~i', $value) === 1) {

                        $add_uid('DOI', ImportUtils::doi($value));
                    }

                    $entry[$C['URLS']][] = $value;
                    break;

                case 'M2':
                case 'AN':
                    if ($tag === 'M2' && preg_match('/pmid:\s*(\d+)/i', $value, $m) === 1) {

                        $add_uid('PMID', $m[1]);
                    }

                    break;

                case 'PMID':
                    $add_uid('PMID', $value);
                    break;

                case 'SN':
                    if (preg_match('/^(97[89][\d\- ]{10,14}|\d[\d\- ]{8,12}[\dXx])$/', $value) === 1) {

                        $add_uid('ISBN', $value);
                    }

                    break;

                case 'KW':
                    $entry[$C['KEYWORDS']][] = $value;
                    break;

                case 'AD':
                    $entry[$C['AFFILIATION']] = $value;
                    break;

                case 'AU':
                case 'A1':
                    [$last, $first_name] = ImportUtils::splitRisName($value);

                    if ($last !== '') {

                        $entry[$C['AUTHOR_LAST_NAME']][] = $last;
                        $entry[$C['AUTHOR_FIRST_NAME']][] = $first_name;
                    }

                    break;

                case 'ED':
                case 'A2':
                    [$last, $first_name] = ImportUtils::splitRisName($value);

                    if ($last !== '') {

                        $entry[$C['EDITOR_LAST_NAME']][] = $last;
                        $entry[$C['EDITOR_FIRST_NAME']][] = $first_name;
                    }

                    break;

                case 'L1':
                    $entry['PDF'] = $entry['PDF'] ?? $value;
                    break;
            }
        }

        if (empty($entry[$C['TITLE']])) {

            $this->skipped[] = ['key' => $key, 'reason' => 'no title'];
            return null;
        }

        // Type: an unknown code becomes a generic reference.
        $type = self::TYPE_ALIASES[$type] ?? 'GEN';
        $il_type = $this->item_meta->convert($type, 'ris', 'il');
        $entry[$C['REFERENCE_TYPE']] = $il_type;
        $entry[$C['BIBTEX_TYPE']] = $this->item_meta->convert($il_type, 'il', 'bibtex');

        if ($key !== null) {

            $entry[$C['BIBTEX_ID']] = $key;
        }

        // Pages, whatever mix of SP and EP is present. An SP that already holds a range is kept as it is.
        if ($sp !== null && $ep !== null && $sp !== $ep && strpos($sp, '-') === false) {

            $entry[$C['PAGES']] = ImportUtils::pages("$sp-$ep");

        } elseif ($sp !== null) {

            $entry[$C['PAGES']] = ImportUtils::pages($sp);

        } elseif ($ep !== null) {

            $entry[$C['PAGES']] = ImportUtils::pages($ep);
        }

        // Date. PY is the publication year. DA is a full date, but it may also be an access or entry date, so it is
        // used only when its year agrees with PY, or when PY gives no usable year.
        $date = null;
        $py_year = $py === null ? null : ImportUtils::date(ImportUtils::splitDate($py)[0]);

        if ($py !== null) {

            [$y, $m, $d] = ImportUtils::splitDate(str_replace('/', '-', rtrim($py, '/')));
            $date = ImportUtils::date($y, $m, $d);
        }

        if ($da !== null) {

            [$y, $m, $d] = ImportUtils::splitDate(str_replace('/', '-', rtrim($da, '/')));
            $da_date = ImportUtils::date($y, $m, $d);

            if ($da_date !== null && ($date === null || substr($da_date, 0, 4) === substr($date, 0, 4))) {

                $date = $da_date;
            }
        }

        if ($date !== null) {

            $entry[$C['PUBLICATION_DATE']] = $date;
        }

        foreach ($uids as [$uid_type, $uid]) {

            $entry[$C['UID_TYPES']][] = $uid_type;
            $entry[$C['UIDS']][] = $uid;
        }

        return $entry;
    }
}
