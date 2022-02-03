<?php
// RacegoController.php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tqdev\PhpCrudApi\Cache\Cache;
use Tqdev\PhpCrudApi\Column\ReflectionService;
use Tqdev\PhpCrudApi\Controller\Responder;
use Tqdev\PhpCrudApi\Database\GenericDB;
use Tqdev\PhpCrudApi\Middleware\Router\Router;

class RacegoController {

    private $responder;
    private $db;

    public function __construct(Router $router, Responder $responder, GenericDB $db, ReflectionService $reflection, Cache $cache)
    {
        $router->register('GET', '/v1/user', array($this, 'getUser'));
        $this->responder = $responder;
        $this->db = $db;
    }

    public function getUser(ServerRequestInterface $request)
    {
        $sql = 'SELECT user.user_id AS id, user.forname AS first_name, user.surname AS last_name FROM user WHERE user_id;';
        $parameters = [];

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parameters);

        $record = $stmt->fetchAll() ?: null;
        if ($record === null) {
            return $this->responder->success([]);
        }
        return $this->responder->success(array($record));
        $records = array($record[0]);

        return $this->responder->success($records);
    }
}