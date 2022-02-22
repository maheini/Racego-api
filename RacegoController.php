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
        // TODO:    add function to update user and add user details
        $router->register('GET', '/v1/user', array($this, 'getUser'));       
        $router->register('GET', '/v1/track', array($this, 'getTrack'));
        $router->register('POST', '/v1/user', array($this, 'addUser'));
        $router->register('DELETE', '/v1/user', array($this, 'deleteUser'));
        $router->register('PUT', '/v1/user', array($this, 'updateUser'));       // rework!
        $router->register('POST', '/v1/ontrack', array($this, 'addOntrack'));
        $router->register('DELETE', '/v1/ontrack', array($this, 'cancelLap'));
        $router->register('PUT', '/v1/ontrack', array($this, 'submitLap'));
        $router->register('GET', '/v1/cathegories', array($this, 'getCathegories'));
        $router->register('GET', '/v1/user/*', array($this, 'getUserDetails'));
        $this->responder = $responder;
        $this->db = $db;
        $this->db->pdo()->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function getUser(ServerRequestInterface $request)
    {
        $sql = "SELECT user_id AS id, surname AS last_name, forname AS first_name, COUNT(lap_time) AS lap_count 
        FROM user LEFT JOIN laps ON user.user_id = laps.user_id_ref 
        GROUP BY forname, surname, user_id ORDER BY forname, surname, lap_count";

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $record = $stmt->fetchAll() ?: null;
        if ($record === null) {
            return $this->responder->success([]);
        }
        return $this->responder->success($record);
    }

    public function getTrack(ServerRequestInterface $request)
    {
        $sql = "SELECT user.user_id AS id, user.forname AS first_name, user.surname AS last_name FROM track
                LEFT JOIN user 
                ON track.user_id_ref = user.user_id ORDER BY track.id";

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $record = $stmt->fetchAll() ?: null;
        if ($record === null) {
            return $this->responder->success([]);
        }
        return $this->responder->success($record);
    }

    public function addUser(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_entry_exists = Tqdev\PhpCrudApi\Record\ErrorCode::ENTRY_ALREADY_EXISTS;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || 
            !property_exists($body, 'first_name') || 
            !property_exists($body, 'last_name'))
        {
            return $this->responder->error($code_validation_failed, "add user", "Invalid input data");
        }
        else if(empty($body->first_name) || 
                empty($body->last_name))
        {
            return $this->responder->error($code_validation_failed , "add user", "Input data is empty");
        }
        
        // check existence
        $sql = "SELECT COUNT(*) FROM `user` WHERE user.forname = :first_name AND user.surname = :last_name";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':first_name', $body->first_name, PDO::PARAM_STR);
        $result->bindParam(':last_name', $body->last_name, PDO::PARAM_STR);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount > 0)
        {
            return $this->responder->error($code_entry_exists , "add user", "Entry already exists");
        }

        // insert
        $sql = "INSERT INTO `user` (user.forname, user.surname) VALUES (:first_name, :last_name)";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':first_name', $body->first_name, PDO::PARAM_STR);
        $result->bindParam(':last_name', $body->last_name, PDO::PARAM_STR);
        $result->execute();
        
        // get new id
        $result = $this->db->pdo()->prepare("SELECT LAST_INSERT_ID()");
        $result->execute();
        $pkValue = $result->fetchColumn(0);
        return $this->responder->success(['inserted_id' =>  (int) $pkValue]);
    }

    public function deleteUser(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_entry_exists = Tqdev\PhpCrudApi\Record\ErrorCode::ENTRY_ALREADY_EXISTS;
        $code_internal_error = Tqdev\PhpCrudApi\Record\ErrorCode::ERROR_NOT_FOUND;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || 
            !property_exists($body, 'id'))
        {
            return $this->responder->error($code_validation_failed, "delete user", "Invalid input data");
        }
        else if(empty($body->id) || 
                $body->id <= 0)
        {
            return $this->responder->error($code_validation_failed , "delete user", "ID is empty or invalid");
        }

        $pdo = $this->db->pdo();

        // start transaction
        if(!$pdo->beginTransaction()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to start transaction");

        // remove cathegories from user
        $sql = "DELETE FROM user_class WHERE user_class.user_id_ref = :id";
        $result = $pdo->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // remove laps from user
        $sql = "DELETE FROM laps WHERE laps.user_id_ref = :id";
        $result = $pdo->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // remove from track
        $sql = "DELETE FROM track WHERE track.user_id_ref = :id";
        $result = $pdo->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        
        // remove user
        $sql = "DELETE FROM user WHERE user.user_id = :id";
        $result = $pdo->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // commit transaction
        if(!$pdo->commit()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to commit transaction");

        // return
        return $this->responder->success(['affected_rows' =>  $result->rowCount()]);
    }


    public function updateUser(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        
        //input validation
        $body = $request->getParsedBody();
        if( !$body || 
            !property_exists($body, 'id') || 
            !property_exists($body, 'first_name') || 
            !property_exists($body, 'last_name'))
        {
            return $this->responder->error($code_validation_failed , "user");
        }
        else if($body->id <= 0 ||
                empty($body->first_name) || 
                empty($body->last_name))
        {
            return $this->responder->error($code_validation_failed , "user");
        }

        // prepare query
        $sql = "UPDATE `user` SET surname = :last_name, forname = :first_name WHERE user_id = :id";
        $stmt = $this->db->pdo()->prepare($sql);
        // bind values
        $stmt->bindParam(':id', $body->id, PDO::PARAM_INT);
        $stmt->bindParam(':first_name', $body->first_name, PDO::PARAM_STR);
        $stmt->bindParam(':last_name', $body->last_name, PDO::PARAM_STR);
        //execute
        $stmt->execute();
        //return
        return $this->responder->success(['affected_rows' =>  $stmt->rowCount()]);
    }

    public function addOntrack(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_entry_exists = Tqdev\PhpCrudApi\Record\ErrorCode::ENTRY_ALREADY_EXISTS;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || 
            !property_exists($body, 'id'))
        {
            return $this->responder->error($code_validation_failed, "post ontrack", "Invalid input data");
        }
        else if(empty($body->id) || 
                $body->id <= 0)
        {
            return $this->responder->error($code_validation_failed , "post ontrack", "Input data is empty");
        }
        
        // check if user_id is valid
        $sql = "SELECT COUNT(*) FROM `user` WHERE user.user_id = :id";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount != 1) return $this->responder->error($code_validation_failed , "post ontrack", "User doesn't exists");

        // check if user is already on track
        $sql = "SELECT COUNT(*) FROM `track` WHERE track.user_id_ref = :id";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount > 0) return $this->responder->error($code_entry_exists , "post ontrack", "User is already on track");

        // insert
        $sql = "INSERT INTO `track` (track.user_id_ref) VALUES (:id)";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        
        // get new id
        $result = $this->db->pdo()->prepare("SELECT LAST_INSERT_ID()");
        $result->execute();
        $pkValue = $result->fetchColumn(0);
        return $this->responder->success(['result' =>  'successful']);
    }

    public function cancelLap(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || 
            !property_exists($body, 'id'))
        {
            return $this->responder->error($code_validation_failed, "delete ontrack", "Invalid input data");
        }
        else if(empty($body->id) || 
                $body->id <= 0)
        {
            return $this->responder->error($code_validation_failed , "delete ontrack", "ID is empty or invalid");
        }

        // remove cathegories from user
        $sql = "DELETE FROM track WHERE track.user_id_ref = :id";
        $result = $this->db->pdo()->prepare($sql);
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
        if( !$body || 
            !property_exists($body, 'id') || 
            !property_exists($body, 'time'))
        {
            return $this->responder->error($code_validation_failed, "put ontrack", "Invalid input data");
        }
        else if(empty($body->id) || 
                $body->id <= 0 ||
                empty($body->time) ||
                !$this->isValidTime($body->time))
        {
            return $this->responder->error($code_validation_failed , "put ontrack", "ID/time is empty or invalid");
        }

        // check if user_id is on_track
        $sql = "SELECT COUNT(*) FROM `track` WHERE track.user_id_ref = :id";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();
        $recordcount = $result->fetchColumn();
        if($recordcount != 1) return $this->responder->error($code_validation_failed , "put ontrack", "User isn't on track");

        // add time and remove user from track
        $pdo = $this->db->pdo();
        
        // start transaction
        if(!$pdo->beginTransaction()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to start transaction");

        // insert time
        $sql = "INSERT INTO laps (lap_time, user_id_ref) VALUES (:time, :id)";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->bindParam(':time', $body->time, PDO::PARAM_STR);
        $result->execute();

        // remove user from track
        $sql = "DELETE FROM track WHERE track.user_id_ref = :id";
        $result = $this->db->pdo()->prepare($sql);
        $result->bindParam(':id', $body->id, PDO::PARAM_INT);
        $result->execute();

        // commit transaction
        if(!$pdo->commit()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to commit transaction");

        // return
        return $this->responder->success(['result' =>  'successful']);
    }

    public function getCathegories(ServerRequestInterface $request)
    {
        $sql = "SELECT DISTINCT class FROM user_class";

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $record = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: null;
        if ($record === null) {
            return $this->responder->success([]);
        }
        return $this->responder->success($record);
    }

    public function getUserDetails(ServerRequestInterface $request)
    {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        
        //input validation
        $id = Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 3);
        if( !isset($id) || $id <= 0)
        {
            return $this->responder->error($code_validation_failed, "get userdetails", "Invalid input data");
        }
        $pdo = $this->db->pdo();

        // get general user_data
        $sql = "SELECT user_id AS id, forname AS first_name, surname AS last_name  FROM `user` WHERE user_id = :id";
        $result = $pdo->prepare($sql);
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        if( !$userData = $result->fetch()){
            return $this->responder->error($code_validation_failed, "get userdetails", "No user found with this ID");
        }

        // get user classes
        $sql = "SELECT class FROM user_class WHERE user_id_ref = :id";
        $result = $pdo->prepare($sql);
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        $classData = $result->fetchAll(PDO::FETCH_COLUMN) ?: null;

        // get laps
        $sql = "SELECT lap_time FROM laps WHERE user_id_ref = :id";
        $result = $pdo->prepare($sql);
        $result->bindParam(':id', $id, PDO::PARAM_INT);
        $result->execute();
        $lapData = $result->fetchAll(PDO::FETCH_COLUMN) ?: null;

        $response = (object) ['id' => $userData["id"], 'first_name' => $userData["first_name"], 'last_name' => $userData["last_name"]];

        $response->class = $classData?$classData:[];
        $response->laps = $lapData?$lapData:[];

        // return
        return $this->responder->success($response);
    }

    function isValidTime(string $time, string $format = 'H:i:s.v'): bool
    {
        $timeObj = DateTime::createFromFormat($format, $time);
        return $timeObj && $timeObj->format($format) == $time;
    }
    
}