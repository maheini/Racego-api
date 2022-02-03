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
        $sql = "SELECT user_id AS id, surname AS last_name, forname AS first_name, COUNT(lap_time) AS lap_count 
        FROM user LEFT JOIN laps ON user.user_id = laps.user_id_ref 
        GROUP BY forname, surname, user_id ORDER BY forname, surname, lap_count";

        $pdo = $this->db->pdo();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([]);

        $record = $stmt->fetchAll() ?: null;
        if ($record === null) {
            return $this->responder->success([]);
        }
        return $this->responder->success($record);
    }
}