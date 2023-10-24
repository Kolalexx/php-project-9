<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Carbon\Carbon;
use Valitron\Validator;
use Dotenv\Dotenv;

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('pdo', function () {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();

    var_dump($_ENV['DATABASE_URL']);

    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    if (!$databaseUrl) {
        throw new \Exception("Error reading database configuration file");
    }

    $dbHost = $databaseUrl['host'];
    $dbPort = $databaseUrl['port'];
    $dbName = ltrim($databaseUrl['path'], '/');
    $dbUser = $databaseUrl['user'];
    $dbPassword = $databaseUrl['pass'];

    $conStr = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
        $dbHost,
        $dbPort,
        $dbName,
        $dbUser,
        $dbPassword
    );

    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    return $pdo;
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
            $pdo = $this->get('pdo');

            $queryUrl = 'SELECT name FROM urls WHERE name = ?';
            $stmt = $pdo->prepare($queryUrl);
            $stmt->execute([$urlName]);
            $selectedUrl = $stmt->fetchAll();

            if (count($selectedUrl) > 0) {
                $queryId = 'SELECT id FROM urls WHERE name = ?';
                $stmt = $pdo->prepare($queryId);
                $stmt->execute([$urlName]);
                $selectId = (string) $stmt->fetchColumn();

                $this->get('flash')->addMessage('success', 'Страница уже существует');
                return $response->withRedirect($router->urlFor('url', ['id' => $selectId]));
            }

            $sql = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$urlName, $createdAt]);
            $lastInsertId = (string) $pdo->lastInsertId();
            $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
            return $response->withRedirect($router->urlFor('url', ['id' => $lastInsertId]));
        } catch (\Throwable | \PDOException $e) {
            echo $e->getMessage();
        }
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
