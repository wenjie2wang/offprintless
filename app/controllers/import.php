<?php

namespace LibrarianApp;

use Exception;
use Librarian\Http\SafeDownloader;
use GuzzleHttp\Psr7\Utils;
use Librarian\Container\DependencyInjector;
use Librarian\External\Crossref;
use Librarian\External\Nasaads;
use Librarian\External\Pmc;
use Librarian\External\Pubmed;
use Librarian\External\Xplore;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use Librarian\Http\ResponseDecorator;
use Librarian\Media\Pdf;

class ImportController extends AppController {

    /**
     * ImportController constructor.
     *
     * @param DependencyInjector $di
     * @throws Exception
     */
    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        $this->session->close();

        // Authorization.
        $this->authorization->signedId(true);
        // Access model: the project role is enforced in the model, so invited-only users may edit where they are editors.
        $this->authorization->permissions('G');
    }

    /**
     * Main.
     *
     * @return string
     * @throws Exception
     */
    public function mainAction(): string {

        return $this->manualAction();
    }

    /**
     * Wizard.
     *
     * @return string
     * @throws Exception
     */
    public function wizardAction(): string {

        // View.
        $view = new ImportView($this->di);
        return $view->wizard();
    }

    /**
     * Import from a UID.
     *
     * @return string
     * @throws Exception
     */
    public function uidAction(): string {

        // Get projects for this user.
        $model = new ProjectModel($this->di);
        $projects = $model->list();
        $model = null;

        // Get all tags.
        $model = new TagsModel($this->di);
        $tags = $model->getTags('library');
        $model = null;

        // View.
        $view = new ImportView($this->di);
        return $view->uid($projects, $tags);
    }

    /**
     * Files. Batch file import form.
     *
     * @return string
     * @throws Exception
     */
    public function fileAction(): string {

        // Get projects for this user.
        $model = new ProjectModel($this->di);
        $projects = $model->list();
        $model = null;

        // Get all tags.
        $model = new TagsModel($this->di);
        $tags = $model->getTags('library');
        $model = null;

        // View.
        $view = new ImportView($this->di);
        return $view->file($projects, $tags);
    }

    /**
     * Batch import. POST request.
     *
     * @return string
     * @throws GuzzleException
     * @throws Exception
     */
    public function batchAction(): string {

        // Imported file is saved to temp file.
        $temp_filename = IL_TEMP_PATH . DIRECTORY_SEPARATOR . uniqid('batch_' . true);
        $uploaded_file = $this->getUploadedFile('file');
        $client_name = null;

        try {

            if (!empty($this->post['remote_url'])) {

                // Guarded download: every hop is checked, redirects to internal addresses are refused, size is capped.
                /** @var SafeDownloader $downloader */
                $downloader = $this->di->get('SafeDownloader');
                $response = $downloader->download($this->post['remote_url'], $temp_filename, ['cookies' => new CookieJar()]);

                // Client filename.
                $decorator = new ResponseDecorator($response);
                $client_name = $decorator->getFilename();

            } elseif (isset($uploaded_file)) {

                $client_name = $uploaded_file->getClientFilename();
                $uploaded_file->moveTo($temp_filename);

            } else {

                // An empty file is treated by getUploadedFile() as no file at all, so say which it was.
                $raw = $this->files['file'] ?? null;

                throw new Exception(
                    $raw !== null && $raw->getError() === UPLOAD_ERR_OK ? 'the file is empty' : 'no file was received',
                    400
                );
            }

            // Only PDF files are accepted.
            $mime_type = mime_content_type($temp_filename);

            if ($mime_type !== 'application/pdf') {

                throw new Exception('only PDF files can be imported', 400);
            }

            $model = new ImportModel($this->di);
            $facts = $model->inspect($temp_filename, $client_name);
            $doi = $facts['doi'] ?? null;

            // The file's own DOI decides whether it is the same article as one already in a project; use the
            // metadata of the chosen provider (Crossref when none) for the title and authors.
            $data = $this->post;
            $data = $this->metadataFor($doi, $data);

            $result = $model->accept($temp_filename, $client_name, $data);

        } finally {

            foreach ([$temp_filename, $temp_filename . '.db', $temp_filename . '.txt'] as $leftover) {

                if (is_file($leftover) && is_writable($leftover)) {

                    unlink($leftover);
                }
            }
        }

        $messages = [
            'created' => 'new item was saved',
            'linked'  => 'this PDF was already in the library and was added to this project',
            'skipped' => 'this PDF is already in this project'
        ];

        $view = new DefaultView($this->di);
        return $view->main(['info' => $messages[$result['status']]]);
    }

    /**
     * Look up the metadata for a DOI found in a PDF: from the provider the user chose, then Crossref. The user's own
     * form values win. A provider that is down does not stop the import: the PDF is filed with its file name as title.
     *
     * @param string|null $doi
     * @param array $data
     * @return array
     * @throws Exception
     */
    private function metadataFor(?string $doi, array $data): array {

        if (empty($doi)) {

            return $data;
        }

        $result = [];

        try {

            if (!empty($data['repository'])) {

                switch ($data['repository']) {

                    case 'xplore':
                        $result = $this->di->getShared('Xplore', $this->app_settings->apiKey('ieee', $this->server))->fetch($doi);
                        break;

                    case 'pubmed':
                        $result = $this->di->get('Pubmed', $this->app_settings->apiKey('ncbi', $this->server, true))->search([['AID' => $doi]], 0);
                        break;

                    case 'pmc':
                        $result = $this->di->get('Pmc', $this->app_settings->apiKey('ncbi', $this->server, true))->search([['DOI' => $doi]], 0);
                        break;

                    case 'scopus':
                        $result = $this->di->getShared('Scopus', $this->app_settings->apiKey('elsevier', $this->server))->fetch($doi);
                        break;

                    case 'sciencedirect':
                        $result = $this->di->getShared('Sciencedirect', $this->app_settings->apiKey('elsevier', $this->server))->fetch($doi);
                        break;

                    case 'nasa':
                        $result = $this->di->get('Nasa', $this->app_settings->apiKey('nasa', $this->server))->search([['doi' => $doi]], 0);
                        break;
                }
            }

            if (empty($result['items'][0]['title'])) {

                $result = $this->di->getShared('Crossref', $this->app_settings->apiKey('crossref', $this->server, true))->fetch($doi);
            }

        } catch (Exception $exc) {

            // Keep going without metadata. The user can edit the item afterwards.
            $result = [];
        }

        return $data + ($result['items'][0] ?? []);
    }

    /**
     * @return string
     * @throws Exception
     * @throws GuzzleException
     */
    public function textAction(): string {

        // GET. Upload form.
        if ($this->request->getMethod() === 'GET') {

            // Get projects for this user.
            $model = new ProjectModel($this->di);
            $projects = $model->list();
            $model = null;

            // Get all tags.
            $model = new TagsModel($this->di);
            $tags = $model->getTags('library');
            $model = null;

            // View.
            $view = new ImportView($this->di);
            return $view->text($projects, $tags);

        } elseif ($this->request->getMethod() === 'POST') {

            if (!empty($this->post['remote_url'])) {

                // Guarded download into a temp file, then read it. Metadata files are small: cap at 20 MB.
                $temp_text = IL_TEMP_PATH . DIRECTORY_SEPARATOR . uniqid('text_');

                /** @var SafeDownloader $downloader */
                $downloader = $this->di->get('SafeDownloader');
                $downloader->download($this->post['remote_url'], $temp_text, ['cookies' => new CookieJar()]);

                $this->post['text'] = (string) file_get_contents($temp_text);
                unlink($temp_text);

            } else {

                $file = $this->getUploadedFile('file');

                if ($file !== null) {

                    $stream = $file->getStream();
                    $this->post['text'] = $stream->getContents();
                }
            }

            if (empty($this->post['text'])) {

                throw new Exception("no input found", 400);
            }

            $model = new ItemModel($this->di);
            $result = $model->importText($this->post);

            // Say what happened, not only that something did. The counts are integers; titles and keys from the file
            // are not put into the message.
            $count = (int) $result['count'];
            $notes = [];

            if (count($result['skipped']) > 0) {

                $notes[] = count($result['skipped']) . ' skipped (no title)';
            }

            if (count($result['failed']) > 0) {

                $notes[] = count($result['failed']) . ' could not be saved';
            }

            if ((int) $result['linked'] > 0) {

                $notes[] = (int) $result['linked'] . ' already in the library and added to this project';
            }

            if (count($result['duplicates']) > 0) {

                $notes[] = count($result['duplicates']) . ' skipped (already in this project)';
            }

            if (count($result['pdf_missing']) > 0) {

                $notes[] = count($result['pdf_missing']) . ' linked PDFs not found in the import folder';
            }

            if ($count === 0 && $notes !== []) {

                $info = 'nothing new was saved: ' . join(', ', $notes);

            } else {

                $info = $count . ($count === 1 ? ' item was saved' : ' items were saved');
                $info .= $notes === [] ? '' : ': ' . join(', ', $notes);
            }

            $view = new DefaultView($this->di);
            return $view->main(['info' => $info]);
        }

        $view = new DefaultView($this->di);
        return $view->main();
    }

    /**
     * Manual.
     *
     * @return string
     * @throws Exception
     */
    public function manualAction(): string {

        // Save from the wizard UID input.
        if (isset($this->post['metadata'])) {

            $metadata = \GuzzleHttp\Utils::jsonDecode($this->post['metadata'], JSON_OBJECT_AS_ARRAY);
            $this->post = $this->post + $metadata;
        }

        // POST. Saving new file from manual form.
        if (isset($this->post['title'])) {

            $error = '';

            $model = new ItemModel($this->di);
            $result = $model->save($this->post);

            // Save PDF save.
            if (!empty($result['item_id'])) {

                $controller = new PdfController($this->di);
                $controller->post['id'] = $result['item_id'];

                try {

                    $controller->saveAction();

                } catch (Exception $ex) {

                    $error = ", but PDF was not";
                }

            }

            $view = new DefaultView($this->di);
            return $view->main(['info' => "new item was saved{$error}"]);
        }

        // GET. Upload form.
        if ($this->request->getMethod() === 'GET') {

            $model = new ProjectModel($this->di);
            $projects = $model->list();
            $model = null;

            $model = new TagsModel($this->di);
            $tags = $model->getTags('library');

            // View.
            $view = new ImportView($this->di);
            return $view->manual($projects, $tags);
        }

        $view = new DefaultView($this->di);
        return $view->main();
    }
}
