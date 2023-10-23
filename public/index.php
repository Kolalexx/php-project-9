<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Carbon\Carbon;
use Valitron\Validator;

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
    return $this->get('renderer')->render($response, "index.phtml");
})->setName('main');

$app->get('/urls', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
   // $users = readUsersList();
    $params = ['flash' => $messages];
    return $this->get('renderer')->render($response, "urls.phtml", $params);
})->setName('urls');

$app->get('/users/{id}', function ($request, $response, array $args) {
    $messages = $this->get('flash')->getMessages();
    $params = ['flash' => $messages];
    $id = $args['id'];
   // $users = readUsersList();
  //  $user = findUser($users, $id);
  //  $params = [
    //    'user' => $user
  //  ];
    if (!$user) {
        return $response->write('Page not found')
            ->withStatus(404);
    }
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('user');

$app->post('/urls', function ($request, $response) use ($router) {
    $urlData = $request->getParsedBodyParam('url');

    $validator = new Validator($urlData);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('Некорректный URL');

    if ($validator->validate()) {
        $createdAt = Carbon::now();
        $url = strtolower($urlData['name']);
        $parseUrl = parse_url($url);
        $urlName = "{$parseUrl['scheme']}://{$parseUrl['host']}";
        try {
            $pdo = Connection::get()->connect();
            echo 'A connection to the PostgreSQL database sever has been established successfully.';
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
        $sql = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$urlName, $createdAt]);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        return $response->withRedirect($router->urlFor('main'));
    }

    $errors = $validator->errors();
    $params = [
        'url' => $urlData['name'],
        'errors' => $errors
    ];
    $response = $response->withStatus(422);
    return $this->get('renderer')->render($response, 'index.phtml', $params);
});

$app->run();
