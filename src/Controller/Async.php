<?php
namespace Bolt\Controller;

use Bolt\Translation\Translator as Trans;
use Guzzle\Http\Exception\RequestException as V3RequestException;
use GuzzleHttp\Exception\RequestException;
use League\Flysystem\FileNotFoundException;
use Silex;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Async extends Base
{
    protected function addRoutes(Silex\ControllerCollection $ctr)
    {
        $ctr->before(array($this, 'before'));

        $ctr->get('/dashboardnews', 'dashboardnews')
            ->bind('dashboardnews');

        $ctr->get('/latestactivity', 'latestactivity')
            ->bind('latestactivity');

        $ctr->get('/filesautocomplete', 'filesautocomplete');

        $ctr->get('/readme/{filename}', 'readme')
            ->assert('filename', '.+')
            ->bind('readme');

        $ctr->get('/widget/{key}', 'widget')
            ->bind('widget');

        $ctr->get('/makeuri', 'makeuri');

        $ctr->get('/lastmodified/{contenttypeslug}/{contentid}', 'lastmodified')
            ->value('contentid', '')
            ->bind('lastmodified');

        $ctr->get('/filebrowser/{contenttype}', 'filebrowser')
            ->assert('contenttype', '.*')
            ->bind('filebrowser');

        $ctr->get('/browse/{namespace}/{path}', 'browse')
            ->assert('path', '.*')
            ->value('namespace', 'files')
            ->value('path', '')
            ->bind('asyncbrowse');

        $ctr->post('/renamefile', 'renamefile')
            ->bind('renamefile');

        $ctr->post('/deletefile', 'deletefile')
            ->bind('deletefile');

        $ctr->post('/duplicatefile', 'duplicatefile')
            ->bind('duplicatefile');

        $ctr->get('/addstack/{filename}', 'addstack')
            ->assert('filename', '.*')
            ->bind('addstack');

        $ctr->get('/tags/{taxonomytype}', 'tags')
            ->bind('tags');

        $ctr->get('/populartags/{taxonomytype}', 'populartags')
            ->bind('populartags');

        $ctr->get('/showstack', 'showstack')
            ->bind('showstack');

        $ctr->get('/omnisearch', 'omnisearch');

        $ctr->post('/folder/rename', 'renamefolder')
            ->bind('renamefolder');

        $ctr->post('/folder/remove', 'removefolder')
            ->bind('removefolder');

        $ctr->post('/folder/create', 'createfolder')
            ->bind('createfolder');

        $ctr->get('/changelog/{contenttype}/{contentid}', 'changelogRecord')
            ->value('contenttype', '')
            ->value('contentid', '0')
            ->bind('changelogrecord');

        $ctr->get('/email/{type}/{recipient}', 'emailNotification')
            ->assert('type', '.*')
            ->bind('emailNotification');
    }

    /**
     * News. Film at 11.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function dashboardnews(Request $request)
    {
        $source = 'http://news.bolt.cm/';
        $news = $this->app['cache']->fetch('dashboardnews'); // Two hours.
        $hostname = $request->getHost();
        $body = '';

        // If not cached, get fresh news.
        if ($news === false) {
            $this->app['logger.system']->info('Fetching from remote server: ' . $source, array('event' => 'news'));

            $driver = $this->app['db']->getDatabasePlatform()->getName();

            $url = sprintf(
                '%s?v=%s&p=%s&db=%s&name=%s',
                $source,
                rawurlencode($this->app->getVersion()),
                phpversion(),
                $driver,
                base64_encode($hostname)
            );

            // Options valid if using a proxy
            if ($this->getOption('general/httpProxy')) {
                $curlOptions = array(
                    'CURLOPT_PROXY'        => $this->getOption('general/httpProxy/host'),
                    'CURLOPT_PROXYTYPE'    => 'CURLPROXY_HTTP',
                    'CURLOPT_PROXYUSERPWD' => $this->getOption('general/httpProxy/user') . ':' .
                                                $this->getOption('general/httpProxy/password')
                );
            }

            // Standard option(s)
            $curlOptions['CURLOPT_CONNECTTIMEOUT'] = 5;

            try {
                if ($this->app['deprecated.php']) {
                    $fetchedNewsData = $this->app['guzzle.client']->get($url, null, $curlOptions)->send()->getBody(true);
                } else {
                    $fetchedNewsData = $this->app['guzzle.client']->get($url, array(), $curlOptions)->getBody(true);
                }

                $fetchedNewsItems = json_decode($fetchedNewsData);

                if ($fetchedNewsItems) {
                    $news = array();

                    // Iterate over the items, pick the first news-item that applies and the first alert we need to show
                    $version = $this->app->getVersion();
                    foreach ($fetchedNewsItems as $item) {
                        $type = ($item->type === 'alert') ? 'alert' : 'information';
                        if (!isset($news[$type])
                            && (empty($item->target_version) || version_compare($item->target_version, $version, '>'))
                        ) {
                            $news[$type] = $item;
                        }
                    }

                    $this->app['cache']->save('dashboardnews', $news, 7200);
                } else {
                    $this->app['logger.system']->error('Invalid JSON feed returned', array('event' => 'news'));
                }
            } catch (RequestException $e) {
                $this->app['logger.system']->critical(
                    'Error occurred during newsfeed fetch',
                    array('event' => 'exception', 'exception' => $e)
                );

                $body .= "<p>Unable to connect to $source</p>";
            } catch (V3RequestException $e) {
                /** @deprecated remove with the end of PHP 5.3 support */
                $this->app['logger.system']->critical(
                    'Error occurred during newsfeed fetch',
                    array('event' => 'exception', 'exception' => $e)
                );

                $body .= "<p>Unable to connect to $source</p>";
            }
        } else {
            $this->app['logger.system']->info('Using cached data', array('event' => 'news'));
        }

        // Combine the body. One 'alert' and one 'info' max. Regular info-items can be disabled, but Alerts can't.
        if (!empty($news['alert'])) {
            $body .= $this->render(
                'components/panel-news.twig',
                array('news' => $news['alert'])
            )->getContent();
        }
        if (!empty($news['information']) && !$this->getOption('general/backend/news/disable')) {
            $body .= $this->render(
                'components/panel-news.twig',
                array('news' => $news['information'])
            )->getContent();
        }

        return new Response($body, Response::HTTP_OK, array('Cache-Control' => 's-maxage=3600, public'));
    }

    /**
     * Get the 'latest activity' for the dashboard.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function latestactivity()
    {
        $activity = $this->app['logger.manager']->getActivity('change', 8);

        $body = '';

        if (!empty($activity)) {
            $body .= $this->render(
                'components/panel-change.twig',
                array(
                    'activity' => $activity
                )
            )->getContent();
        }

        $activity = $this->app['logger.manager']->getActivity('system', 8, null, 'authentication');

        if (!empty($activity)) {
            $body .= $this->render(
                'components/panel-system.twig',
                array(
                    'activity' => $activity
                )
            )->getContent();
        }

        return new Response($body, Response::HTTP_OK, array('Cache-Control' => 's-maxage=3600, public'));
    }

    /**
     * Return autocomplete data for a file name.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function filesautocomplete(Request $request)
    {
        $term = $request->get('term');

        $extensions = $request->query->get('ext');
        $files = $this->getFilesystemManager()->search($term, $extensions);

        $this->app['debug'] = false;

        return $this->json($files);
    }

    /**
     * Render a widget, and return the HTML, so it can be inserted in the page.
     *
     * @param string $key
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function widget($key)
    {
        $html = $this->app['extensions']->renderWidget($key);

        return new Response($html, Response::HTTP_OK, array('Cache-Control' => 's-maxage=180, public'));
    }

    /**
     * Render an extension's README.md file.
     *
     * @param string $filename
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function readme($filename)
    {
        $paths = $this->app['resources']->getPaths();

        $filename = $paths['extensionspath'] . '/vendor/' . $filename;

        // don't allow viewing of anything but "readme.md" files.
        if (strtolower(basename($filename)) != 'readme.md') {
            $this->abort(Response::HTTP_UNAUTHORIZED, 'Not allowed');
        }
        if (!is_readable($filename)) {
            $this->abort(Response::HTTP_UNAUTHORIZED, 'Not readable');
        }

        $readme = file_get_contents($filename);

        // Parse the field as Markdown, return HTML
        $html = $this->app['markdown']->text($readme);

        return new Response($html, Response::HTTP_OK, array('Cache-Control' => 's-maxage=180, public'));
    }

    /**
     * Generate a URI based on request parmaeters
     *
     * @param Request $request
     *
     * @return string
     */
    public function makeuri(Request $request)
    {
        $uri = $this->app['storage']->getUri(
            $request->query->get('title'),
            $request->query->get('id'),
            $request->query->get('contenttypeslug'),
            $request->query->getBoolean('fulluri'),
            true,
            $request->query->get('slugfield') //for multipleslug support
        );

        return $uri;
    }

    /**
     * Fetch a JSON encoded set of taxonomy specific tags.
     *
     * @param string $taxonomytype
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function tags($taxonomytype)
    {
        $table = $this->getOption('general/database/prefix', 'bolt_');
        $table .= 'taxonomy';

        $query = $this->app['db']->createQueryBuilder()
            ->select("DISTINCT $table.slug")
            ->from($table)
            ->where('taxonomytype = :taxonomytype')
            ->orderBy('slug', 'ASC')
            ->setParameters(array(
                ':taxonomytype' => $taxonomytype
            ));

        $results = $query->execute()->fetchAll();

        return $this->json($results);
    }

    /**
     * Fetch a JSON encoded set of the most popular taxonomy specific tags.
     *
     * @param Request $request
     * @param string  $taxonomytype
     *
     * @return integer|\Symfony\Component\HttpFoundation\JsonResponse
     */
    public function populartags(Request $request, $taxonomytype)
    {
        $table = $this->getOption('general/database/prefix', 'bolt_');
        $table .= 'taxonomy';

        $query = $this->app['db']->createQueryBuilder()
            ->select('slug, COUNT(slug) AS count')
            ->from($table)
            ->where('taxonomytype = :taxonomytype')
            ->groupBy('slug')
            ->orderBy('count', 'DESC')
            ->setMaxResults($request->query->getInt('limit', 20))
            ->setParameters(array(
                ':taxonomytype' => $taxonomytype
            ));

        $results = $query->execute()->fetchAll();

        usort(
            $results,
            function ($a, $b) {
                if ($a['slug'] == $b['slug']) {
                    return 0;
                }

                return ($a['slug'] < $b['slug']) ? -1 : 1;
            }
        );

        return $this->json($results);
    }

    /**
     * Perform an OmniSearch search and return the results.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function omnisearch(Request $request)
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 3) {
            return $this->json(array());
        }

        $options = $this->app['omnisearch']->query($query);

        return $this->json($options);
    }

    /**
     * Latest {contenttype} to show a small listing in the sidebars.
     *
     * @param string       $contenttypeslug
     * @param integer|null $contentid
     *
     * @return Response
     */
    public function lastmodified($contenttypeslug, $contentid = null)
    {
        // Let's find out how we should determine what the latest changes were:
        $contentLogEnabled = (bool) $this->getOption('general/changelog/enabled');

        if ($contentLogEnabled) {
            return $this->lastmodifiedByContentLog($this->app, $contenttypeslug, $contentid);
        } else {
            return $this->lastmodifiedSimple($this->app, $contenttypeslug);
        }
    }

    /**
     * Only get latest {contenttype} record edits based on date changed.
     *
     * @param string $contenttypeslug
     *
     * @return Response
     */
    private function lastmodifiedSimple($contenttypeslug)
    {
        // Get the proper contenttype.
        $contenttype = $this->getContentType($contenttypeslug);

        // Get the 'latest' from the requested contenttype.
        $latest = $this->getContent($contenttype['slug'], array('limit' => 5, 'order' => 'datechanged DESC', 'hydrate' => false));

        $context = array(
            'latest'      => $latest,
            'contenttype' => $contenttype
        );

        $body = $this->render('components/panel-lastmodified.twig', array('context' => $context))->getContent();

        return new Response($body, Response::HTTP_OK, array('Cache-Control' => 's-maxage=60, public'));
    }

    /**
     * Get last modified records from the content log.
     *
     * @param string  $contenttypeslug
     * @param integer $contentid
     *
     * @return Response
     */
    private function lastmodifiedByContentLog($contenttypeslug, $contentid)
    {
        // Get the proper contenttype.
        $contenttype = $this->getContentType($contenttypeslug);

        // get the changelog for the requested contenttype.
        $options = array('limit' => 5, 'order' => 'date', 'direction' => 'DESC');

        if (intval($contentid) == 0) {
            $isFiltered = false;
        } else {
            $isFiltered = true;
            $options['contentid'] = intval($contentid);
        }

        $changelog = $this->app['logger.manager.change']->getChangelogByContentType($contenttype['slug'], $options);

        $context = array(
            'changelog'   => $changelog,
            'contenttype' => $contenttype,
            'contentid'   => $contentid,
            'filtered'    => $isFiltered,
        );

        $body = $this->render('components/panel-lastmodified.twig', array('context' => $context))->getContent();

        return new Response($body, Response::HTTP_OK, array('Cache-Control' => 's-maxage=60, public'));
    }

    /**
     * List pages in given contenttype, to easily insert links through the Wysywig editor.
     *
     * @param string $contenttype
     *
     * @return mixed
     */
    public function filebrowser($contenttype)
    {
        $results = array();

        foreach ($this->app['storage']->getContentTypes() as $contenttype) {
            $records = $this->getContent($contenttype, array('published' => true, 'hydrate' => false));

            foreach ($records as $record) {
                $results[$contenttype][] = array(
                    'title' => $record->gettitle(),
                    'id'    => $record->id,
                    'link'  => $record->link()
                );
            }
        }

        $context = array(
            'results' => $results,
        );

        return $this->render('filebrowser/filebrowser.twig', array('context' => $context));
    }

    /**
     * List browse on the server, so we can insert them in the file input.
     *
     * @param string  $namespace
     * @param string  $path
     * @param Request $request
     *
     * @return mixed
     */
    public function browse($namespace, $path, Request $request)
    {
        // No trailing slashes in the path.
        $path = rtrim($path, '/');

        $filesystem = $this->getFilesystemManager()->getFilesystem($namespace);

        // $key is linked to the fieldname of the original field, so we can
        // Set the selected value in the proper field
        $key = $request->query->get('key');

        // Get the pathsegments, so we can show the path.
        $pathsegments = array();
        $cumulative = "";
        if (!empty($path)) {
            foreach (explode("/", $path) as $segment) {
                $cumulative .= $segment . "/";
                $pathsegments[$cumulative] = $segment;
            }
        }

        try {
            $filesystem->listContents($path);
        } catch (\Exception $e) {
            $msg = Trans::__("Folder '%s' could not be found, or is not readable.", array('%s' => $path));
            $this->addFlash('error', $msg);
        }

        list($files, $folders) = $filesystem->browse($path, $this->app);

        $context = array(
            'namespace'    => $namespace,
            'files'        => $files,
            'folders'      => $folders,
            'pathsegments' => $pathsegments,
            'key'          => $key
        );

        return $this->render('files_async/files_async.twig', array('context' => $context), array(
            'title', Trans::__('Files in %s', array('%s' => $path))
        ));
    }

    /**
     * Add a file to the user's stack.
     *
     * @param string $filename
     *
     * @return true
     */
    public function addstack($filename)
    {
        $this->app['stack']->add($filename);

        return true;
    }

    /**
     * Render a user's current stack.
     *
     * @param Request $request
     *
     * @return \Twig_Markup
     */
    public function showstack(Request $request)
    {
        $count = $request->query->get('items', 10);
        $options = $request->query->get('options', false);

        $context = array(
            'stack'     => $this->app['stack']->listitems($count),
            'filetypes' => $this->app['stack']->getFileTypes(),
            'namespace' => $this->app['upload.namespace'],
            'canUpload' => $this->getUsers()->isAllowed('files:uploads')
        );

        switch ($options) {
            case 'minimal':
                $twig = 'components/stack-minimal.twig';
                break;

            case 'list':
                $twig = 'components/stack-list.twig';
                break;

            case 'full':
            default:
                $twig = 'components/panel-stack.twig';
                break;
        }

        return $this->render($twig, array('context' => $context));
    }

    /**
     * Rename a file within the files directory tree.
     *
     * @param Request $request The HTTP Request Object containing the GET Params
     *
     * @return Boolean Whether the renaming action was successful
     */
    public function renamefile(Request $request)
    {
        $namespace  = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $oldName    = $request->request->get('oldname');
        $newName    = $request->request->get('newname');

        try {
            return $this->getFilesystemManager()->rename("$namespace://$parentPath/$oldName", "$parentPath/$newName");
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete a file on the server.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function deletefile(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $filename = $request->request->get('filename');

        try {
            return $this->getFilesystemManager()->delete("$namespace://$filename");
        } catch (FileNotFoundException $e) {
            return false;
        }
    }

    /**
     * Duplicate a file on the server.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function duplicatefile(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $filename = $request->request->get('filename');

        $filesystem = $this->getFilesystemManager()->getFilesystem($namespace);

        $extensionPos = strrpos($filename, '.');
        $destination = substr($filename, 0, $extensionPos) . "_copy" . substr($filename, $extensionPos);
        $n = 1;

        while ($filesystem->has($destination)) {
            $extensionPos = strrpos($destination, '.');
            $destination = substr($destination, 0, $extensionPos) . "$n" . substr($destination, $extensionPos);
            $n = rand(0, 1000);
        }
        if ($filesystem->copy($filename, $destination)) {
            return true;
        }

        return false;
    }

    /**
     * Rename a folder within the files directory tree.
     *
     * @param Request $request The HTTP Request Object containing the GET Params
     *
     * @return Boolean Whether the renaming action was successful
     */
    public function renamefolder(Request $request)
    {
        $namespace  = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $oldName    = $request->request->get('oldname');
        $newName    = $request->request->get('newname');

        try {
            return $this->getFilesystemManager()->rename("$namespace://$parentPath$oldName", "$parentPath$newName");
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete a folder recursively if writeable.
     *
     * @param Request $request The HTTP Request Object containing the GET Params
     *
     * @return Boolean Whether the renaming action was successful
     */
    public function removefolder(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $folderName = $request->request->get('foldername');

        try {
            return $this->getFilesystemManager()->deleteDir("$namespace://$parentPath$folderName");
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a new folder.
     *
     * @param Request $request The HTTP Request Object containing the GET Params
     *
     * @return Boolean Whether the creation was successful
     */
    public function createfolder(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $folderName = $request->request->get('foldername');

        try {
            return $this->getFilesystemManager()->createDir("$namespace://$parentPath$folderName");
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate the change log box for a single record in edit.
     *
     * @param string  $contenttype
     * @param integer $contentid
     *
     * @return string
     */
    public function changelogRecord($contenttype, $contentid)
    {
        $options = array(
            'contentid' => $contentid,
            'limit'     => 4,
            'order'     => 'date',
            'direction' => 'DESC'
        );

        $context = array(
            'contenttype' => $contenttype,
            'entries'     => $this->app['logger.manager.change']->getChangelogByContentType($contenttype, $options)
        );

        return $this->render('components/panel-change-record.twig', array('context' => $context));
    }

    /**
     * Send an e-mail ping test.
     *
     * @param Request $request
     * @param string  $type
     *
     * @return Response
     */
    public function emailNotification(Request $request, $type)
    {
        $user = $this->getUsers()->getCurrentUser();

        // Create an email
        $mailhtml = $this->render(
            'email/pingtest.twig',
            array(
                'sitename' => $this->getOption('general/sitename'),
                'user'     => $user['displayname'],
                'ip'       => $request->getClientIp()
            )
        )->getContent();

        $senderMail = $this->getOption('general/mailoptions/senderMail', 'bolt@' . $request->getHost());
        $senderName = $this->getOption('general/mailoptions/senderName', $this->getOption('general/sitename'));

        $message = $this->app['mailer']
            ->createMessage('message')
            ->setSubject('Test email from ' . $this->getOption('general/sitename'))
            ->setFrom(array($senderMail  => $senderName))
            ->setTo(array($user['email'] => $user['displayname']))
            ->setBody(strip_tags($mailhtml))
            ->addPart($mailhtml, 'text/html');

        $this->app['mailer']->send($message);

        return new Response('Done', Response::HTTP_OK);
    }

    /**
     * Middleware function to do some tasks that should be done for all asynchronous
     * requests.
     */
    public function before(Request $request)
    {
        // Start the 'stopwatch' for the profiler.
        $this->app['stopwatch']->start('bolt.async.before');

        // If there's no active session, don't do anything.
        if (!$this->getUsers()->isValidSession()) {
            $this->abort(Response::HTTP_UNAUTHORIZED, 'You must be logged in to use this.');
        }

        // Stop the 'stopwatch' for the profiler.
        $this->app['stopwatch']->stop('bolt.async.before');
    }
}
