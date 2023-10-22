<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Carbon\Carbon;
use Dotenv\Dotenv;

use PostgreSQLTutorial\Connection;

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(MethodOverrideMiddleware::class);

$router = $app->getRouteCollector()->getRouteParser();

$app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {
    $params = [
        'name' => [],
        'errors' => []
    ];
    return $this->get('renderer')->render($response, "index.phtml", $params);
})->setName('main');

$app->post('/', function ($request, $response) use ($router) {
    $urlData = $request->getParsedBodyParam('url');
    //!!!!!!$validator = new App\Validator();!!!!!!
    $errors = [];// $validator->validate($postData)
    $createdAt = Carbon::now();

    if (count($errors) === 0) {
        try {
            $pdo = Connection::get()->connect();
            echo 'A connection to the PostgreSQL database sever has been established successfully.';
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
        $sql = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$urlData['name'], $createdAt]);
       // $this->get('flash')->addMessage('success', 'Post has been created');
        return $response->withRedirect($router->urlFor('main'));
    }

    $params = [
        'name' => $urlData,
        'errors' => $errors
    ];
    $response = $response->withStatus(422);
    return $this->get('renderer')->render($response, 'index.phtml', $params);
});

$app->run();
