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
    $outDir = $this->get('settings')['archive_directory'] . DIRECTORY_SEPARATOR . 'jb1Xzanox6i8Zyse4DcYD8sZqy0'; //
    if ($handle = opendir($outDir)) {
        /* This is the correct way to loop over the directory. */
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                $community = array(
                    'url' => "/archive/jb1Xzanox6i8Zyse4DcYD8sZqy0/$entry",
                    'name' => $entry
                );
                array_push($communities, $community);
            }
        }
        closedir($handle);
    }

    $response = $this->view->render($response, 'index.html', array('communities' => $communities));

    return $response;
});

$app->get('/test', function (Request $request, Response $response, array $args) {

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

    $response = $this->view->render($response, 'results.html', array("output" => $output));

    return $response;
});

$app->post('/csv', function (Request $request, Response $response, array $args) {

    // E.g. https://plus.google.com/communities/116965157741523529510
    if (!isset($_POST['communityId'])) {
        $response->getBody()->write('You must supply a community ID.');
        return $response->withStatus(400);
    }

    $url = parse_url($_POST['communityId'], PHP_URL_PATH);
    $communityId = explode("/", $url)[2]; # /communities/116965157741523529510

    if (!$communityId) {
        $response->getBody()->write('You must supply a community ID.');
        return $response->withStatus(400);
    }

    # create our API interface class
    $plus = new GPlusArchiver();

    # Set up a search for a community
    $query = "in:$communityId";

    /*
        Response

        Property name	Value	    Description	Notes
        kind	        string	    Identifies this resource as a collection of activities. Value: "plus#activityFeed".
        nextPageToken	string	    The continuation token, which is used to page through large result sets. Provide this value in a subsequent request to return the next page of results.
        selfLink	    string	    Link to this activity resource.
        nextLink	    string	    Link to the next page of activities.
        title	        string	    The title of this collection of activities, which is a truncated portion of the content.
        updated	        datetime	The time at which this collection of activities was last updated. Formatted as an RFC 3339 timestamp.
        id	            string	    The ID of this collection of activities. Deprecated.
        items[]	        list	    The activities in this page of results.
                                    See: https://developers.google.com/+/web/api/rest/latest/activities#resource
        etag	        etag	    ETag of this response for caching purposes.
     */

    $outDir = $this->get('settings')['archive_directory'];
    if (!is_dir($outDir)) {
        mkdir($outDir);
    }

    $csvFile = $outDir . DIRECTORY_SEPARATOR . "$communityId.csv";
    $pageToken = null;
    if (file_exists($csvFile)) {
        # read the last page token to pick up where we left off, if necessary
        $rows = file($csvFile);
        $last_row = array_pop($rows);
        $data = str_getcsv($last_row);
        $pageToken = $data[0];
    }

    # This might take a while...
    set_time_limit($this->get('settings')['timeout_minutes'] * 60);
    $fp = fopen($csvFile, 'a');  # w = write, a = append
    try {
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

                    # This will split the etag into directory + file
                    $outFile = str_replace('"', "", $item['etag']);
                    $outFile = explode('/', $outFile);
                    $tmpDir = $outDir . DIRECTORY_SEPARATOR . $outFile[0];
                    if (!is_dir($tmpDir)) {
                        mkdir($tmpDir);
                    }
                    $outFile = $tmpDir . DIRECTORY_SEPARATOR . $outFile[1] . ".phpobj";

                    # for now just serialize the object
                    if (!file_exists($outFile)) {
                        file_put_contents($outFile, serialize($item));
                    }

                    # Write a CSV file, too!
                    $row = array(
                        $pageToken,
                        #$item['etag'],
                        $item['published'],
                        #$item['actor'],
                        $item['url']
                    );

                    fputcsv($fp, $row);
                    // var_export($row);
                }
            }
            #var_export($results['items']);
            //print('</pre>');
        } while (!is_null($pageToken));
    } finally {
        fclose($fp);
    }

    #$response = $this->view->render($response, 'results.html', $output );

    return $response;
});

$app->run();