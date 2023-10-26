<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Carbon\Carbon;
use Valitron\Validator;
use Dotenv\Dotenv;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('pdo', function () {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->Load();

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
    $pdo = $this->get('pdo');
    $queryUrls = 'SELECT id, name FROM urls ORDER BY created_at DESC';
    $stmt = $pdo->prepare($queryUrls);
    $stmt->execute();
    $selectedUrls = $stmt->fetchAll(\PDO::FETCH_UNIQUE);

    $params = ['data' => $selectedUrls];
    return $this->get('renderer')->render($response, "urls.phtml", $params);
})->setName('urls');

$app->get('/urls/{id}', function ($request, $response, array $args) {
    $messages = $this->get('flash')->getMessages();

    $id = $args['id'];

    $pdo = $this->get('pdo');
    $query = 'SELECT * FROM urls WHERE id = ?';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    $urlSelect = $stmt->fetch();

    if (count($urlSelect) === 0) {
        return $response->write('Страница не найдена!')
            ->withStatus(404);
    }
    $queryCheck = 'SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC';
    $stmt = $pdo->prepare($queryCheck);
    $stmt->execute([$id]);
    $selectedCheck = $stmt->fetchAll();
    $params = [
        'flash' => $messages,
        'data' => $urlSelect,
        'checkData' => $selectedCheck
    ];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('url');

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

$app->post('/urls/{url_id}/checks', function ($request, $response, array $args) use ($router) {
    $id = $args['url_id'];

    try {
        $pdo = $this->get('pdo');

        $createdAt = Carbon::now();

        $sql = "INSERT INTO url_checks (url_id, created_at) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id, $createdAt]);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    return $response->withRedirect($router->urlFor('url', ['id' => $id]));
});

$app->run();
