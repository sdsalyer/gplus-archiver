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

$app = new \Slim\App(['settings' => $config]);

/*
 * Dependencies
 */
$container = $app->getContainer();

$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig('../templates', [
        'cache' => '../cache'
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

$app->run();