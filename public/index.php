<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require_once '../vendor/autoload.php';

/*
 * Configuration
 */
$config['displayErrorDetails'] = true;
//$config['db']['host']   = 'localhost';
//$config['db']['user']   = 'user';
//$config['db']['pass']   = 'password';
//$config['db']['dbname'] = 'exampleapp';

# Constants should be defined somewhere in lib/ folder (e.g. secret.php)
$config['archive_directory'] = ARCHIVE_DIRECTORY;
$config['timeout_minutes'] = TIMEOUT_MINUTES;

$app = new \Slim\App(['settings' => $config]);

/*
 * Dependencies
 */
$container = $app->getContainer();

$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig('../templates', [
        #'cache' => '../cache'
        'cache' => false
    ]);

    // Instantiate and add Slim specific extension
    $basePath = rtrim(str_ireplace('index.php', '', $container->get('request')->getUri()->getBasePath()), '/');
    $view->addExtension(new Slim\Views\TwigExtension($container->get('router'), $basePath));

    return $view;
};

$container['logger'] = function ($c) {
    $logger = new \Monolog\Logger('gplus-archiver-logger');
    $file_handler = new \Monolog\Handler\StreamHandler('../logs/gplus-archiver.log');
    $logger->pushHandler($file_handler);
    return $logger;
};

//$container['db'] = function ($c) {
//    $db = $c['settings']['db'];
//    $pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'],
//        $db['user'], $db['pass']);
//    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
//    return $pdo;
//};

/*
 * Routes
 */
$app->get('/', function (Request $request, Response $response, array $args) {

    # display the communities already archived
    $communities = array();
    $archiveDir = $this->get('settings')['archive_directory'];
    $directories = glob($archiveDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

    foreach ($directories as $dir) {
        $name = 'unnamed';
        $id = basename($dir);

        $indexFile = $dir . DIRECTORY_SEPARATOR . 'index.txt';
        if (file_exists($indexFile)) {
            $name = file_get_contents($indexFile);
        }

        # don't load in-progress archives
        $errorFile = $dir . DIRECTORY_SEPARATOR . 'pageToken.txt';
        if (!file_exists($errorFile)) {
            array_push($communities, array(
                'communityName' => $name,
                'communityId' => $id
            ));
        }
    }

    # order the list
    usort($communities, function ($item1, $item2) {
        return $item1['communityName'] <=> $item2['communityName'];
    });

    $response = $this->view->render($response, 'index.html', array('communities' => $communities));

    return $response;
});

$app->get('/test', function (Request $request, Response $response, array $args) {

    $desc = 'Lone Wolf Roleplaying (Actual Plays)';
    $name = '';
    $category = '';

    GPlusArchiver::parseCommunityNameAndCategory($desc, $name, $category);

    echo "Desc: $desc<br />";
    echo "Name: $name<br />";
    echo "Category: $category<br />";

    return $response;
});

$app->get('/download[/{communityId}]', function (Request $request, Response $response, array $args) {

    if (!array_key_exists('communityId', $args)) {
        $response->getBody()->write('You must supply a community ID.');
        return $response->withStatus(400);
    }

    $communityId = $args['communityId'];

    # fetch the community name
    $name = 'unnamed';
    $archiveDir = $this->get('settings')['archive_directory'] . DIRECTORY_SEPARATOR . $communityId;
    $indexFile = $archiveDir . DIRECTORY_SEPARATOR . 'index.txt';
    if (file_exists($indexFile)) {
        $name = file_get_contents($indexFile);
    }

    $output = array(
        'communityName' => $name,
        'communityId' => $communityId
    );

    $response = $this->view->render($response, 'download.html', $output);

    return $response;
});

$app->get('/archive[/{communityId}]', function (Request $request, Response $response, array $args) {

    if (!array_key_exists('communityId', $args)) {
        # TODO: List archived communities, instead?
        $response->getBody()->write('You must supply a community ID.');
        return $response->withStatus(400);
    }

    # fetch the community name
    $name = 'unnamed';
    $archiveDir = $this->get('settings')['archive_directory'] . DIRECTORY_SEPARATOR . $args['communityId'];
    $indexFile = $archiveDir . DIRECTORY_SEPARATOR . 'index.txt';
    if (file_exists($indexFile)) {
        $name = file_get_contents($indexFile);
    }

    $zipFile = $archiveDir . DIRECTORY_SEPARATOR . urlencode($name) . '.zip';

    # Create the zip if it doesn't exist
    if (!file_exists($zipFile)) {
        $jsonDir = $archiveDir . DIRECTORY_SEPARATOR . 'json';
        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
            $dots = array('.', '..');
            foreach (new DirectoryIterator($jsonDir) as $fileInfo) {
                if (in_array($fileInfo->getFilename(), $dots)) {
                    continue;
                }
                $fileName = $fileInfo->getPathname();
                print $fileName . "<br />";
                $zip->addFile($fileName, $fileInfo->getFilename());
            }

            $zip->close();
        }
    }

    # ZIP download
    $fh = fopen($zipFile, 'rb');
    $stream = new \Slim\Http\Stream($fh);

    # send the stream output with zip headers
    return $response->withHeader('Content-Type', 'application/force-download')
        ->withHeader('Content-Type', 'application/octet-stream')
        ->withHeader('Content-Type', 'application/download')
        ->withHeader('Content-Description', 'File Transfer')
        ->withHeader('Content-Transfer-Encoding', 'binary')
        ->withHeader('Content-Disposition', 'attachment; filename="' . basename($zipFile) . '"')
        ->withHeader('Expires', '0')
        ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
        ->withHeader('Pragma', 'public')
        ->withBody($stream);
});

$app->post('/archive', function (Request $request, Response $response, array $args) {

    // E.g. https://plus.google.com/communities/116965157741523529510
    if (!isset($_POST['communityId'])) {
        $response->getBody()->write('You must supply a community URL.');
        return $response->withStatus(400);
    }

    $url = parse_url($_POST['communityId'], PHP_URL_PATH);
    $communityId = explode("/", $url)[2]; # /communities/116965157741523529510

    if (!$communityId) {
        $response->getBody()->write('You must supply a community ID.');
        return $response->withStatus(400);
    }

    # Set up some directories
    try {
        # Archive root directory
        $archiveDir = $this->get('settings')['archive_directory'];
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir);
        }

        # community directory
        $outDir = $archiveDir . DIRECTORY_SEPARATOR . $communityId;
        if (!is_dir($outDir)) {
            mkdir($outDir);
        }

        # serialized object directory
        $objDir = $outDir . DIRECTORY_SEPARATOR . 'phpobj';
        if (!is_dir($objDir)) {
            mkdir($objDir);
        }

        # json directory
        $jsonDir = $outDir . DIRECTORY_SEPARATOR . 'json';
        if (!is_dir($jsonDir)) {
            mkdir($jsonDir);
        }
    } catch (Exception $e) {
        $msg = 'Unable to create directories';
        $this->logger->addError($msg);
        $this->logger->addError($e->getMessage() . PHP_EOL . $e->getTraceAsString());
        $response->getBody()->write($msg);
        return $response->withStatus(400);
    }

    // TODO: figure out if anything is new/different? I think we'd just examine the first page of results

    // TODO: skip all this if already archived!

    # Pick up from checkpoint
    $pageToken = null;
    $pageTokenFile = $outDir . DIRECTORY_SEPARATOR . "pageToken.txt";
    if (file_exists($pageTokenFile)) {
        $pageToken = file_get_contents($pageTokenFile);
        unlink($pageTokenFile);
    }

    # create an initial checkpoint to flag this as in-progress
    file_put_contents($pageTokenFile, '');

    try {
        set_time_limit($this->get('settings')['timeout_minutes'] * 60);

        # create our API interface object
        $plus = new GPlusArchiver();

        # Set up a search for a community
        $query = "in:$communityId";

        # search and iterate over result pages
        $this->logger->addInfo("Start [$communityId] ----------------------------------------------------------------");
        do {
            $params = array(
                'orderBy' => 'recent',   # best or recent
                'maxResults' => '20',    # 20 max ...
                'pageToken' => $pageToken
            );
            $results = $plus->activitiesSearch($query, $params);

            if (!$results) {
                $response->getBody()->write('Something went wrong querying the Google API.');
                return $response->withStatus(400);
            }

            if (count($results['items']) == 0) {
                # Known bug: https://code.google.com/archive/p/google-plus-platform/issues/406
                $pageToken = null;
            } else {
                # Process the results
                $pageToken = $results['nextPageToken'];
                foreach ($results['items'] as $item) {
                    # Parse the community name and category
                    $communityDescription = $item['access']['description'];
                    $communityName = '';
                    $category = '';
                    GPlusArchiver::parseCommunityNameAndCategory($communityDescription, $communityName, $category);

                    # Note down the community name...
                    $indexFile = $outDir . DIRECTORY_SEPARATOR . "index.txt";
                    if (!file_exists($indexFile)) {
                        file_put_contents($indexFile, $communityName);
                    }

                    # Pull in comments
                    $activityId = $item['id'];
                    $comments = array();
                    if ($item['object']['replies']['totalItems'] > 0) {
                        # TODO process attachments in replies...
                        $comments = $plus->getComments($activityId);
                    }

                    # Pull in attachments
                    # TODO: fetch the actual attachments
                    $attachments = $item['object']['attachments'];

                    # Set up our JSON structure
                    $row = array(
                        'id' => $activityId,
                        'etag' => $item['etag'], # not entirely sure this will be useful
                        'published' => $item['published'],
                        'updated' => $item['updated'],
                        'title' => $item['title'],
                        'url' => $item['url'],
                        'author' => [
                            'displayName' => $item['actor']['displayName'],
                            'id' => $item['actor']['id'],
                            'url' => $item['actor']['url'],
                            'profileImage' => $item['actor']['image']['url']
                        ],
                        'content' => $item['object']['content'],
                        'comments' => $comments,
                        'attachments' => $attachments,
                        'category' => $category
                    );

                    # Serialize the results so we can read them again later without an API call
                    $itemId = $item['id'];
                    $outFile = $objDir . DIRECTORY_SEPARATOR . "$itemId.phpobj";
                    if (!file_exists($outFile)) {
                        file_put_contents($outFile, serialize($item));
                    }

                    # Create some json
                    $pubDate = new DateTime($row['published']);
                    $fileName = $pubDate->format('Y-m-d-H.i.s') . '_' . urlencode($row['author']['displayName']); //str_replace(" ", "_", $row['author']['displayName']);
                    $outFile = $jsonDir . DIRECTORY_SEPARATOR . "$fileName.json";
                    if (!file_exists($outFile)) {
                        file_put_contents($outFile, json_encode($row));
                    }
                }
            }
        } while (!is_null($pageToken));
    } catch (Exception $e) {
        $this->logger->addError($e->getMessage() . PHP_EOL . $e->getTraceAsString());

        # set a checkpoint we can pick back up from
        if ($pageToken) {
            $this->logger->addError("Failed at pageToken: $pageToken");
            file_put_contents($pageTokenFile, $pageToken);
        }

        $response->getBody()->write('Something went wrong... Please try again.');
        return $response->withStatus(500);
    } finally {
        $this->logger->addInfo("Done! [$communityId] ----------------------------------------------------------------");
    }

    # cleanup the checkpoint file
    if (file_exists($pageTokenFile)) {
        unlink($pageTokenFile);
    }

    # TODO: show something?
    # This should send to the ZIP download route...
    return $response->withRedirect("/download/$communityId");
});

$app->run();