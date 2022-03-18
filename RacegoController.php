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
        $router->register('GET', '/v1/track', array($this, 'getTrack'));
        $router->register('GET', '/v1/user/*', array($this, 'getUserDetails'));
        $router->register('PUT', '/v1/user/*', array($this, 'setUserDetails'));
        $router->register('POST', '/v1/user', array($this, 'addUser'));
        $router->register('DELETE', '/v1/user', array($this, 'deleteUser'));
        $router->register('POST', '/v1/ontrack', array($this, 'addOntrack'));
        $router->register('DELETE', '/v1/ontrack', array($this, 'cancelLap'));
        $router->register('PUT', '/v1/ontrack', array($this, 'submitLap'));
        $router->register('GET', '/v1/categories', array($this, 'getCategories'));
        $router->register('GET', '/v1/ranking/*', array($this, 'getRanking'));

        $this->responder = $responder;
        $this->db = $db;
        $this->db->pdo()->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function getUser(ServerRequestInterface $request)
    {
        $sql = "SELECT user_id AS id, surname AS last_name, forname AS first_name, COUNT(lap_time) AS lap_count 
        FROM user LEFT JOIN laps ON user.user_id = laps.user_id_ref 
        GROUP BY forname, surname, user_id ORDER BY forname, surname, lap_count";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute();

        $record = $stmt->fetchAll() ?: [];
        return $this->responder->success($record);
    }

    public function getTrack(ServerRequestInterface $request)
    {
        $sql = "SELECT user.user_id AS id, user.forname AS first_name, user.surname AS last_name FROM track
                LEFT JOIN user 
                ON track.user_id_ref = user.user_id ORDER BY track.id";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute();

        $record = $stmt->fetchAll() ?: [];
        return $this->responder->success($record);
    }

    public function getUserDetails(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
    
        //input validation
        $id = Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 3);
        if( !$id || (int)$id <= 0) return $this->responder->error($code_validation_failed, "get userdetails", "Invalid input data");

        $pdo = $this->db->pdo();

        // get general user_data
        $result = $pdo->prepare("SELECT user_id AS id, forname AS first_name, surname AS last_name  FROM `user` WHERE user_id = :id");
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        if( !$userData = $result->fetch())
            return $this->responder->error($code_validation_failed, "get userdetails", "No user found with this ID");

        // get user classes
        $result = $pdo->prepare("SELECT class FROM user_class WHERE user_id_ref = :id ORDER BY class");
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        $classData = $result->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // get laps
        $result = $pdo->prepare("SELECT lap_time FROM laps WHERE user_id_ref = :id ORDER BY id");
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        $lapData = $result->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $response = (object) ['id' => $userData["id"], 'first_name' => $userData["first_name"], 'last_name' => $userData["last_name"]];

        $response->class = $classData;
        $response->laps = $lapData;

        // return
        return $this->responder->success($response);
    }

    public function setUserDetails(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_entry_exists = Tqdev\PhpCrudApi\Record\ErrorCode::ENTRY_ALREADY_EXISTS;
        $code_internal_error = Tqdev\PhpCrudApi\Record\ErrorCode::ERROR_NOT_FOUND;

        //input validation
        $id = Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 3);
        $body = $request->getParsedBody();
        if( !$body || !$id || (int)$id <= 0 || empty($body->first_name) || empty($body->last_name))
            return $this->responder->error($code_validation_failed, "update user", "Invalid input data");

        // start transaction
        $pdo = $this->db->pdo();
        if(!$pdo->beginTransaction()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to start transaction");

        // check if any user with id exists
        $result = $this->db->pdo()->prepare("SELECT COUNT(*) FROM `user` WHERE user_id = :id");
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount <= 0) return $this->responder->error($code_validation_failed , "update user", "ID invalid");

        // check if first_name and last_name are already given
        $result = $this->db->pdo()->prepare("SELECT COUNT(*) FROM `user` WHERE user.forname = :first_name AND user.surname = :last_name AND user_id <> :id");
        $result->bindParam(':first_name', $body->first_name, PDO::PARAM_STR);
        $result->bindParam(':last_name', $body->last_name, PDO::PARAM_STR);
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount > 0) return $this->responder->error($code_entry_exists , "update user", "first_name and last_name already exist");

        // update user
        $result = $pdo->prepare("UPDATE `user` SET forname = :first_name, surname = :last_name WHERE user_id = :id");
        $result->bindParam(':first_name', $body->first_name, PDO::PARAM_STR);
        $result->bindParam(':last_name', $body->last_name, PDO::PARAM_STR);
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();

        // delete all race-classes
        $result = $pdo->prepare("DELETE FROM user_class WHERE user_id_ref = :id");
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        // add all race_class
        if(!empty($body->class) && is_array($body->class)){
            foreach($body->class as $classname){
                if(!empty($classname) && is_string($classname)){
                    $result = $pdo->prepare("INSERT INTO user_class (class, user_id_ref) VALUES (:class, :id)");
                    $result->bindParam(':class', $classname, PDO::PARAM_STR);
                    $result->bindParam(':id', $id, PDO::PARAM_INT);
                    $result->execute();
                } else return $this->responder->error($code_validation_failed , "update user", "class is invalid");
            }
        }

        // delete all lap-times
        $result = $pdo->prepare("DELETE FROM laps WHERE user_id_ref = :id");
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        // add all lap_times
        if(!empty($body->laps) && is_array($body->laps)){
            foreach($body->laps as $lap_time){
                if(!empty($lap_time) && $this->isValidTime($lap_time)){
                    $result = $pdo->prepare("INSERT INTO laps (lap_time, user_id_ref) VALUES (:lap_time, :id)");
                    $result->bindParam(':lap_time', $lap_time, PDO::PARAM_STR);
                    $result->bindParam(':id', $id, PDO::PARAM_INT);
                    $result->execute();
                } else return $this->responder->error($code_validation_failed , "update user", "lap_time is invalid");
            }
        }

        // commit transaction
        if(!$pdo->commit()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to commit transaction");

        // return
        return $this->responder->success(['result' => 'successful']);
    }

    public function addUser(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_entry_exists = Tqdev\PhpCrudApi\Record\ErrorCode::ENTRY_ALREADY_EXISTS;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || empty($body->first_name) || empty($body->last_name))
            return $this->responder->error($code_validation_failed, "add user", "Invalid input data");
        
        $pdo = $this->db->pdo();

        // check existence
        $result = $pdo->prepare("SELECT COUNT(*) FROM `user` WHERE user.forname = :first_name AND user.surname = :last_name");
        $result->bindParam(':first_name', $body->first_name, PDO::PARAM_STR);
        $result->bindParam(':last_name', $body->last_name, PDO::PARAM_STR);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount > 0)
            return $this->responder->error($code_entry_exists , "add user", "Entry already exists");
        
        // insert
        $result = $pdo->prepare("INSERT INTO `user` (user.forname, user.surname) VALUES (:first_name, :last_name)");
        $result->bindParam(':first_name', $body->first_name, PDO::PARAM_STR);
        $result->bindParam(':last_name', $body->last_name, PDO::PARAM_STR);
        $result->execute();
        
        // get new id
        $result = $pdo->prepare("SELECT LAST_INSERT_ID()");
        $result->execute();
        $pkValue = $result->fetchColumn(0);
        return $this->responder->success(['inserted_id' =>  (int) $pkValue]);
    }

    public function deleteUser(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_internal_error = Tqdev\PhpCrudApi\Record\ErrorCode::ERROR_NOT_FOUND;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || empty($body->id) || (int)$body->id <= 0)
            return $this->responder->error($code_validation_failed, "delete user", "Invalid input data");

        $pdo = $this->db->pdo();

        // start transaction
        if(!$pdo->beginTransaction()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to start transaction");

        // remove categories from user
        $result = $pdo->prepare("DELETE FROM user_class WHERE user_class.user_id_ref = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // remove laps from user
        $result = $pdo->prepare("DELETE FROM laps WHERE laps.user_id_ref = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // remove from track
        $result = $pdo->prepare("DELETE FROM track WHERE track.user_id_ref = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        
        // remove user
        $result = $pdo->prepare("DELETE FROM user WHERE user.user_id = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // commit transaction
        if(!$pdo->commit()) return $this->responder->error($code_internal_error , 'Transaction failed', 'Failed to commit transaction');

        // return
        if($result->rowCount() > 0)
            return $this->responder->success(['result' =>  'successful']);
        else 
            return $this->responder->error($code_internal_error , "result", "no rows affeccted");
    }

    public function addOntrack(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_entry_exists = Tqdev\PhpCrudApi\Record\ErrorCode::ENTRY_ALREADY_EXISTS;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || empty($body->id) || (int)$body->id <= 0)
            return $this->responder->error($code_validation_failed, "post ontrack", "Invalid input data");

        $pdo = $this->db->pdo();
        
        // check if user_id is valid
        $result = $pdo->prepare("SELECT COUNT(*) FROM `user` WHERE user.user_id = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount != 1) return $this->responder->error($code_validation_failed , "post ontrack", "User doesn't exists");

        // check if user is already on track
        $result = $pdo->prepare("SELECT COUNT(*) FROM `track` WHERE track.user_id_ref = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount > 0) return $this->responder->error($code_entry_exists , "post ontrack", "User is already on track");

        // insert
        $result = $pdo->prepare("INSERT INTO `track` (track.user_id_ref) VALUES (:id)");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        return $this->responder->success(['result' =>  'successful']);
    }

    public function cancelLap(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || empty($body->id) || (int)$body->id <= 0)
            return $this->responder->error($code_validation_failed, "delete ontrack", "Invalid input data");

        // remove categories from user
        $result = $this->db->pdo()->prepare("DELETE FROM track WHERE track.user_id_ref = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // return
        return $this->responder->success(['affected_rows' =>  $result->rowCount()]);
    }

    public function submitLap(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_internal_error = Tqdev\PhpCrudApi\Record\ErrorCode::ERROR_NOT_FOUND;

        //input validation
        $body = $request->getParsedBody();
        if( !$body ||  empty($body->id) ||  empty($body->time))
            return $this->responder->error($code_validation_failed, "put ontrack", "Invalid input data");

        // start transaction
        $pdo = $this->db->pdo();
        if(!$pdo->beginTransaction()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to start transaction");

        // check if user_id is on_track
        $result = $pdo->prepare("SELECT COUNT(*) FROM `track` WHERE track.user_id_ref = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount != 1) return $this->responder->error($code_validation_failed , "put ontrack", "User isn't on track");

        // insert time
        $result = $pdo->prepare("INSERT INTO laps (lap_time, user_id_ref) VALUES (:time, :id)");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->bindParam(':time', $body->time, PDO::PARAM_STR);
        $result->execute();

        // remove user from track
        $result = $pdo->prepare("DELETE FROM track WHERE track.user_id_ref = :id");
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // commit transaction
        if(!$pdo->commit()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to commit transaction");

        // return
        return $this->responder->success(['result' =>  'successful']);
    }

    public function getCategories()
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT DISTINCT class FROM user_class ORDER BY class");
        $stmt->execute();
        $record = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return $this->responder->success($record);
    }

    public function getRanking(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
    
        //input validation
        $class = Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 3);
        
        if( !$class || (string)$class == '')   // empty String?
            return $this->responder->error($code_validation_failed, "get ranking", "Invalid input data");

        $pdo = $this->db->pdo();

        // get general user_data
        $data = [];
        if ($class == "all") {
            $query = "SELECT CONCAT(`user`.forname, ' ', `user`.surname) AS name, ".
            "MIN(SUBSTRING(TIME_FORMAT(lap_time, '%i:%s.%f'),1,9)) AS 'time', DENSE_RANK() OVER (ORDER BY MIN(lap_time) ASC) ".
            "AS 'rank' FROM laps ".
            "LEFT JOIN `user` ON laps.user_id_ref = `user`.user_id ".
            "GROUP BY user_id_ref ORDER BY 'rank' ASC";

            $result = $pdo->prepare($query);
            $result->execute();

            $data = $result->fetchAll() ?: [];
        }
        else {
            $query = "SELECT CONCAT(`user`.forname, ' ', `user`.surname) AS name, ".
            "MIN(SUBSTRING(TIME_FORMAT(lap_time, '%i:%s.%f'),1,9)) AS 'time', DENSE_RANK() OVER (ORDER BY MIN(lap_time) ASC) ".
            "AS 'rank' FROM laps ".
            "LEFT JOIN `user` ON laps.user_id_ref = `user`.user_id ".
            "WHERE laps.user_id_ref IN (SELECT user_class.user_id_ref FROM user_class WHERE class = :class) ".
            "GROUP BY user_id_ref ORDER BY 'rank' ASC";

            $result = $pdo->prepare($query);
            $result->bindParam(':class', $class, PDO::PARAM_STR);
            $result->execute();

            $data = $result->fetchAll() ?: [];
        }

        return $this->responder->success($data);
    }

    function isValidTime(string $time, string $format = 'H:i:s.v'): bool
    {
        $timeObj = DateTime::createFromFormat($format, $time);
        return $timeObj && $timeObj->format($format) == $time;
    }
    
}