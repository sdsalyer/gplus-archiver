<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require '../vendor/autoload.php';

/*
 * Configuration
 */
$config['displayErrorDetails'] = true;
//$config['db']['host']   = 'localhost';
//$config['db']['user']   = 'user';
//$config['db']['pass']   = 'password';
//$config['db']['dbname'] = 'exampleapp';

# TODO: composer not auto-loading from lib/
if (!defined('GAPI_API_KEY')) {
    define('GAPI_API_KEY', 'AIzaSyAFcDZXBXqX6y2K9EHmv6v3-w2oTekPIRA');
}

if (!defined('GAPI_CLIENT_ID')) {
    define('GAPI_CLIENT_ID', '872909385168-imn92ke4523o7g4q5a36np6394bk38qv.apps.googleusercontent.com');
}

if (!defined('GAPI_CLIENT_SECRET')) {
    define('GAPI_CLIENT_SECRET', 'RA0fAKijwWGqyUwqGZ84Vqm6');
}
$config['gapi_api_key'] = GAPI_API_KEY;
$config['gapi_client_id'] = GAPI_CLIENT_ID;
$config['gapi_client_secret'] = GAPI_CLIENT_SECRET;

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
    $this->logger->addInfo('Home');

    $response = $this->view->render($response, 'index.html');
//    return $this->view->render($response, 'profile.html', [
//        'name' => $args['name']
//    ]);
    return $response;
});

$app->get('/hello/{name}', function (Request $request, Response $response, array $args) {
    $name = $args['name'];
    $response->getBody()->write("Hello, $name");

    $this->logger->addInfo('Something interesting happened');

    return $response;
});

$app->get('/test', function (Request $request, Response $response, array $args) {

    $client = new Google_Client();
    $client->setApplicationName('gplus-archiver');

    # These are for OAUTH
    #$client->setClientId($this->get('settings')['gapi_client_id']);
    #$client->setClientSecret($this->get('settings')['gapi_client_secret']);

    # This is for API key
    $client->setDeveloperKey($this->get('settings')['gapi_api_key']);

    #'http://gplus-archiver.local/test');
    $referer = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $client->setRedirectUri($referer);
    $client->setHttpClient(new \GuzzleHttp\Client(['headers' => ['referer' => $referer]]));

    # This is for the javascript? discovery docs
    #$client->addScope('https://www.googleapis.com/discovery/v1/apis/plus/v1/rest');

    # This is for G+ API access
    $client->addScope('https://www.googleapis.com/auth/plus.login');

    # Now we can create the service client
    $plus = new Google_Service_Plus($client);

    /*
        Request

        Parameter name	Value	            Description
        Required query parameters
        query	        string	            Full-text search query string.

        Optional query parameters
        language	    string	            Specify the preferred language to search with. See search language codes for available values.
        maxResults	    unsigned integer	The maximum number of activities to include in the response, which is used for paging. For any response, the actual number returned might be less than the specified maxResults. Acceptable values are 1 to 20, inclusive. (Default: 10)
        orderBy	        string	            Specifies how to order search results. Acceptable values are:
                                                "best": Sort activities by relevance to the user, most relevant first.
                                                "recent": Sort activities by published date, most recent first. (default)
        pageToken	    string	            The continuation token, which is used to page through large result sets. To get the next page of results, set this parameter to the value of "nextPageToken" from the previous response. This token can be of any length.
     */
    # Set up a search for a community
    $communityId = '116965157741523529510'; # TODO: load this from route args / form input
    $query = "in:$communityId";
    $params = array(
        'orderBy' => 'recent',   # best or recent
        'maxResults' => '20',    # 20 max ...
        'pageToken' => null
    );
    $results = $plus->activities->search($query, $params);


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

    $outDir = "D:\webdev\gplus-archiver\archive";
    if (!is_dir($outDir)) {
        mkdir($outDir);
    }
    //print('<pre>');
    $output = array();
    foreach ($results['items'] as $item) {
        #print "Result: {$item['object']['content']}\n";
        #var_export($item);
//        print(json_encode($item));
//        print(",\n");
//        array_push($output, array(
//            'url' => $item['url'],
//            'title' => $item['title'],
//            'published' => $item['published'],
//            'actor' => $item['actor'],
//            'access' => $item['access'],
//            'object' => $item['object']
//        ));
        $outFile = str_replace('"', "", $item['etag']);
        #$outFile = str_replace('/', "_", $outFile);
        $outFile = explode('/', $outFile);
        $tmpDir = $outDir . DIRECTORY_SEPARATOR . $outFile[0];
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir);
        }
        $outFile = $tmpDir . DIRECTORY_SEPARATOR . $outFile[1] . ".phpobj";

        if (!file_exists($outFile)) {
            file_put_contents($outFile, serialize($item));
        }

    }
    #var_export($results['items']);
    //print('</pre>');

    #$response = $this->view->render($response, 'results.html', $output );

    return $response;
});

$app->run();