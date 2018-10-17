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
$config['gapi_api_key'] = GAPI_API_KEY;
$config['gapi_client_id'] = GAPI_CLIENT_ID;
$config['gapi_client_secret'] = GAPI_CLIENT_SECRET;
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

    $communities = array();
    $archiveDir = $this->get('settings')['archive_directory'];
    $directories = glob($archiveDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

    foreach ($directories as $dir) {
        $name = 'unnamed';
        $url = '/archive/' . basename($dir);

        $indexFile = $dir . DIRECTORY_SEPARATOR . 'index.txt';
        if (file_exists($indexFile)) {
            $name = file_get_contents($indexFile);
        }

        array_push($communities, array(
            'name' => $name,
            'url' => $url
        ));
    }

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

$app->get('/jsontest', function (Request $request, Response $response, array $args) {

    $outDir = $this->get('settings')['archive_directory'] . DIRECTORY_SEPARATOR . 'jb1Xzanox6i8Zyse4DcYD8sZqy0'; //

    $output = array();
    if ($handle = opendir($outDir)) {
        /* This is the correct way to loop over the directory. */
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                $obj = file_get_contents($outDir . DIRECTORY_SEPARATOR . $entry);
                $obj = unserialize($obj);
                //var_export($obj);
                array_push($output, $obj);
            }
        }
        closedir($handle);
    }

    # sort by date desc
    usort($output, function ($a, $b) {
        return $b['published'] <=> $a['published'];
    });

    foreach ($output as $obj) {
        $tmpDir = $this->get('settings')['archive_directory'] . DIRECTORY_SEPARATOR . 'json';


        $outFile = $tmpDir . DIRECTORY_SEPARATOR . $fileName . ".json";

        echo $outFile . "<br />";
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir);
        }

        # write json file
        if (!file_exists($outFile)) {
            file_put_contents($outFile, json_encode($obj));
        }
    }

    return $response; //->withJson($output);
});

$app->get('/archive[/{communityId}]', function (Request $request, Response $response, array $args) {

    if (!array_key_exists('communityId', $args)) {
        # TODO: List archived communities, instead?
        $response->getBody()->write('You must supply a community ID.');
        return $response->withStatus(400);
    }

    $name = 'unnamed';
    $archiveDir = $this->get('settings')['archive_directory'] . DIRECTORY_SEPARATOR . $args['communityId'];
    $indexFile = $archiveDir . DIRECTORY_SEPARATOR . 'index.txt';
    if (file_exists($indexFile)) {
        $name = file_get_contents($indexFile);
    }

    $zipFile = $archiveDir . DIRECTORY_SEPARATOR . urlencode($name) . '.zip';
    $jsonDir = $archiveDir . DIRECTORY_SEPARATOR . 'json';
    print $zipFile . "<br />";
    if (!file_exists($zipFile)) {
        // Create the zip
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

    # TODO: something about these headers isn't right
    # ZIP download headers
    $response = $response->withHeader('Content-Type', 'application/zip')
        ->withHeader('Content-Disposition', 'attachment;filename="' . basename($zipFile) . '"')
        ->withHeader('Expires', '0')
        #->withHeader('Cache-Control', 'must-revalidate')
        ->withHeader('Pragma', 'public')
        ->withHeader('Content-Length', filesize($zipFile));

    readfile($zipFile);

    return $response;
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
        $this->logger->addError("$msg: " . $e->getMessage());
        $response->getBody()->write($msg);
        return $response->withStatus(400);
    }

    // TODO: check directory contents and
    //       - figure out where we left off
    //       - figure out if anything is new/different

    try {
        set_time_limit($this->get('settings')['timeout_minutes'] * 60);
        $pageToken = null;

        # create our API interface class
        $plus = new GPlusArchiver();

        # Set up a search for a community
        $query = "in:$communityId";

        do {
            $params = array(
                'orderBy' => 'recent',   # best or recent
                'maxResults' => '20',    # 20 max ...
                'pageToken' => $pageToken
            );
            # Returns Google_Service_Plus_ActivityFeed
            $results = $plus->activitiesSearch($query, $params);

            if (!$results) {
                $response->getBody()->write('Something went wrong querying the Google API.');
                return $response->withStatus(400);
            }

            if (count($results['items']) == 0) {
                # Known bug: https://code.google.com/archive/p/google-plus-platform/issues/406
                $pageToken = null;
            } else {
                $pageToken = $results['nextPageToken'];
                foreach ($results['items'] as $item) {

//                    # This will split the etag into directory + file
//                    $outFile = str_replace('"', "", $item['etag']);
//                    $outFile = explode('/', $outFile);
//                    $tmpDir = $outDir . DIRECTORY_SEPARATOR . $outFile[0];
//                    if (!is_dir($tmpDir)) {
//                        mkdir($tmpDir);
//                    }
//                    $outFile = $tmpDir . DIRECTORY_SEPARATOR . $outFile[1] . ".phpobj";

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
                        $comments = $plus->getComments($activityId);
                    }

                    # Pull in attachments
                    $attachments = $item['object']['attachments'];

                    # Only these fields
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

                    # Let's serialize the results so we can read them again later without an API call
                    $itemId = $item['id'];
                    $outFile = $objDir . DIRECTORY_SEPARATOR . "$itemId.phpobj";
                    if (!file_exists($outFile)) {
                        file_put_contents($outFile, serialize($item));
                    }

                    # Create some json
                    $pubDate = new DateTime($row['published']);
                    $fileName = $pubDate->format('Y-m-d-H.i.s') . '_' . str_replace(" ", "_", $row['author']['displayName']);
                    $outFile = $jsonDir . DIRECTORY_SEPARATOR . "$fileName.json";
                    if (!file_exists($outFile)) {
                        file_put_contents($outFile, json_encode($row));
                    }
                }
            }
        } while (!is_null($pageToken));
    } catch (Exception $e) {
        $this->logger->addError($e->getMessage());
    }
//    finally {
//
//    }

    # TODO: show something or redirect back home
    #$response = $this->view->render($response, 'results.html', $output );

    return $response;
});

$app->run();