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
        $router->register('POST', '/v1/race/manager', array($this, 'addManager'));
        $router->register('DELETE', '/v1/race/manager', array($this, 'deleteManager'));
        
        $this->responder = $responder;
        $this->db = $db;
        $this->db->pdo()->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }


    function addManager(){
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $body = $request->getParsedBody();
        if(!$body) return $this->responder->error($code_validation_failed, "add manager", "Invalid input data");

        $userName = strval($body->username);
        $raceID = strval($body->$id);

        if( !$this->validateAdminAccess($raceID) ) return $this->responder->error(401, 'Unauthorized');

        if( $raceID <= 0 || empty($userName) || !ctype_alpha($userName)){
            return $this->responder->error($code_validation_failed, "add manager", "Invalid input data");
        }

        $pdo = $this->db->pdo();
        $result = $pdo->prepare("SELECT id FROM login WHERE username = :username");
        $result->bindParam(':username', $userName, PDO::PARAM_STR);
        $result->execute();
        $userID = $result->fetchColumn(0);
        if( $userID <= 0 ){
            return $this->responder->error($code_validation_failed, "add manager", "Invalid input data");
        }

        // Check if the user is already a manager
        $result = $pdo->prepare("SELECT id FROM race_relations WHERE login_id = :login_id");
        $result->bindParam(':login_id', $userID, PDO::PARAM_INT);
        $result->execute();
        if( $result->rowCount() > 0 ){
            $code_entry_exists = Tqdev\PhpCrudApi\Record\ErrorCode::ENTRY_ALREADY_EXISTS;
            return $this->responder->error($code_entry_exists , "add manager", "User is already a manager");
        }

        $result = $pdo->prepare( "INSERT INTO race_relations (login_id, race_id) VALUES (:login_id, :race_id)" );
        $result->bindParam(':login_id', $userID, PDO::PARAM_INT);
        $result->bindParam(':race_id', $raceID, PDO::PARAM_INT);
        $result->execute();

        return $this->responder->success(['affected_rows' =>  $result->rowCount()]);
    }

    function deleteManager(){
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $body = $request->getParsedBody();
        if(!$body) return $this->responder->error($code_validation_failed, "delete manager", "Invalid input data");

        $userName = strval($body->username);
        $raceID = strval($body->$id);

        if( !$this->validateAdminAccess($raceID) ) return $this->responder->error(401, 'Unauthorized');

        if( $raceID <= 0 || empty($body->race_name) || !ctype_alpha($body->race_name)){
            return $this->responder->error($code_validation_failed, "delete manager", "Invalid input data");
        }

        $pdo = $this->db->pdo();
        $result = $pdo->prepare("SELECT id FROM login WHERE username = :username");
        $result->bindParam(':username', $userName, PDO::PARAM_STR);
        $result->execute();
        $userID = $result->fetchColumn(0);
        if( $userID <= 0 ){
            return $this->responder->error($code_validation_failed, "delete manager", "Invalid input data");
        }

        $result = $pdo->prepare( "DELETE FROM race_relations WHERE login_id = :login_id" );
        $result->bindParam(':login_id', $userID, PDO::PARAM_INT);
        $result->execute();

        return $this->responder->success(['affected_rows' =>  $result->rowCount()]);
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
