<?php

// RaceManageController

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tqdev\PhpCrudApi\Cache\Cache;
use Tqdev\PhpCrudApi\Column\ReflectionService;
use Tqdev\PhpCrudApi\Controller\Responder;
use Tqdev\PhpCrudApi\Database\GenericDB;
use Tqdev\PhpCrudApi\Middleware\Router\Router;

class RaceManageController {

    private $responder;
    private $db;

    public function __construct(Router $router, Responder $responder, GenericDB $db, ReflectionService $reflection, Cache $cache)
    {
        $router->register('DELETE', '/v1/race/manager', array($this, 'deleteManager'));
        
        $this->responder = $responder;
        $this->db = $db;
        $this->db->pdo()->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }


    }

        function validateRaceAccess( $raceID ){
        $raceID = intval($raceID);
        if ( !isset($_SESSION['user']['id']) ){
            return false;
        } else if ( $raceID <= 0 ){
            return false;
        } else {
            $userID = $_SESSION['user']['id'];
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare("SELECT * FROM race_relations WHERE login_id=:login_id AND race_id = :race_id");
            $stmt->bindParam(':login_id', $userID, PDO::PARAM_INT);
            $stmt->bindParam(':race_id', $raceID, PDO::PARAM_INT);
            $stmt->execute();
            $recordcount = $stmt->fetchColumn();
            if( !$recordcount ){
                return false;
            } else {
                return true;
            }
        }
    }

    function validateAdminAccess( $raceID ){
        $raceID = intval($raceID);
        if ( !isset($_SESSION['user']['id']) ){
            return false;
        } else if ( $raceID <= 0 ){
            return false;
        } else {
            $userID = $_SESSION['user']['id'];
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare("SELECT * FROM race_relations WHERE login_id=:login_id AND race_id = :race_id AND is_admin = true");
            $stmt->bindParam(':login_id', $userID, PDO::PARAM_INT);
            $stmt->bindParam(':race_id', $raceID, PDO::PARAM_INT);
            $stmt->execute();
            $recordcount = $stmt->fetchColumn();
            if( !$recordcount ){
                return false;
            } else {
                return true;
            }
        }
    }
