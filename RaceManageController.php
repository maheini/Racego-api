<?php

// RaceManageController

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
        $router->register('GET', '/v1/races', array($this, 'getRaces'));
        $router->register('POST', '/v1/race', array($this, 'addRace'));
        $router->register('DELETE', '/v1/race', array($this, 'deleteRace'));

        $router->register('GET', '/v1/race/*', array($this, 'getRaceDetails'));
        $router->register('POST', '/v1/race/*', array($this, 'updateRaceDetails'));
        
        $this->responder = $responder;
        $this->db = $db;
        $this->db->pdo()->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    function getRaces($request){
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;

        $userID = intval($_SESSION['user']['id']);

        if( $userID <= 0){
            return $this->responder->error($code_validation_failed, "get races", "Invalid input data");
        } 
        
        $pdo = $this->db->pdo();
        $sql =  "SELECT race_relations.race_id as id, race_overview.race_name as name, a.manager as manager, race_relations.is_admin FROM race_relations ".
                "LEFT JOIN ( ".
                "SELECT race_id, COUNT(login_id) as manager FROM race_relations GROUP BY race_id) AS a ".
                "ON race_relations.race_id = a.race_id ".
                "LEFT JOIN race_overview ON race_relations.race_id = race_overview.race_id ".
                "WHERE race_relations.login_id = :login_id ORDER BY id";
        $result = $pdo->prepare( $sql );
        $result->bindParam(':login_id', $userID, PDO::PARAM_INT);
        $result->execute();

        $record = $result->fetchAll() ?: [];
        return $this->responder->success($record);
    }

    function addRace($request){
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;

        //input validation
        $body = $request->getParsedBody();
        if( !$body || empty($body->name) || !is_string($body->name)){
            return $this->responder->error($code_validation_failed, "add race", "Invalid input data");
        }

        // start transaction
        $pdo = $this->db->pdo();
        if(!$pdo->beginTransaction()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to start transaction");


        // Add race
        $result = $pdo->prepare("INSERT INTO race_overview (race_name) VALUES (:race_name)");
        $result->bindParam(':race_name', $body->name, PDO::PARAM_STR);
        $result->execute();
        if( $result->rowCount() <= 0 ){
            $hhh->rollBack();
            return $this->responder->error($code_internal_error , "add race", "Failed to insert race.");
        }

        // get new id
        $result = $pdo->prepare("SELECT LAST_INSERT_ID()");
        $result->execute();
        $pkValue = $result->fetchColumn(0);
        
        $result = $pdo->prepare("INSERT INTO race_relations (login_id, race_id, is_admin) VALUES (:login_id, :race_id, true)");
        $result->bindParam(':login_id', $_SESSION['user']['id'], PDO::PARAM_INT);
        $result->bindParam(':race_id', $pkValue, PDO::PARAM_INT);
        $result->execute();

        // commit transaction
        if(!$pdo->commit()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to commit transaction");

        // return
        return $this->responder->success(['race_id' =>  $pkValue]);
    }

    function deleteRace($request) {
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $body = $request->getParsedBody();
        if(!$body) return $this->responder->error($code_validation_failed, "delete race", "Invalid input data");

        $raceID = intval($body->id);

        if( !$this->validateAdminAccess($raceID) ) return $this->responder->error(401, 'Unauthorized');

        if( $raceID <= 0 ){
            return $this->responder->error($code_validation_failed, "delete race", "Invalid input data");
        }
        
        $pdo = $this->db->pdo();
        $sql =  "DELETE race_overview, race_relations, laps, track, user, user_class FROM race_overview ".
                "LEFT JOIN race_relations ON race_overview.race_id = race_relations.race_id ".
                "LEFT JOIN laps ON race_overview.race_id = laps.race_id ".
                "LEFT JOIN track ON race_overview.race_id = track.race_id ".
                "LEFT JOIN user ON race_overview.race_id = user.race_id ".
                "LEFT JOIN user_class ON race_overview.race_id = user_class.race_id ".
                "WHERE race_overview.race_id = :race_id";

        $result = $pdo->prepare( $sql );
        $result->bindParam(':race_id', $raceID, PDO::PARAM_INT);
        $result->execute();

        return $this->responder->success(['affected_rows' =>  $result->rowCount()]);
    }

    function getRaceDetails($request){
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;

        //input validation
        $raceID = intval(Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 3));

        if( $raceID <= 0){
            return $this->responder->error($code_validation_failed, "get race", "Invalid input data");
        }

        if( !$this->validateRaceAccess($raceID) ) return $this->responder->error(401, 'Unauthorized');

        // get race id & name
        $pdo = $this->db->pdo();
        $sql = 'SELECT race_overview.race_id AS id, race_overview.race_name AS name FROM race_overview WHERE race_overview.race_id = :id';
        $result = $pdo->prepare( $sql );
        $result->bindParam( ':id', $raceID );
        $result->execute();
        if( !$raceData = $result->fetch())
            return $this->responder->error($code_validation_failed, "get racedetails", "No race details found with this ID");

        // get managers
        $pdo = $this->db->pdo();
        $sql = 'SELECT login.username as username, race_relations.is_admin as is_admin FROM race_relations '.
                'LEFT JOIN login ON login.id = race_relations.login_id '.
                'WHERE race_relations.race_id = :race_id';
        $result = $pdo->prepare( $sql );
        $result->bindParam( ':race_id', $raceID );
        $result->execute();
        $raceManager = $result->fetchAll() ?: [];

        $response = (object) array( 'id' => $raceData['id'], 'name' => $raceData['name'] );
        $response->race_manager = $raceManager;

        // return
        return $this->responder->success($response);
    }

    function updateRaceDetails($request){
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $code_internal_error = Tqdev\PhpCrudApi\Record\ErrorCode::ERROR_NOT_FOUND;


        //input validation
        $raceID = intval(Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 3));

        if( $raceID <= 0){
            return $this->responder->error($code_validation_failed, "update race", "Invalid input data");
        }

        if( !$this->validateRaceAccess($raceID) ) return $this->responder->error(401, 'Unauthorized');


        // Get body and validate all values
        $body = $request->getParsedBody();
        if( !$body )
            return $this->responder->error($code_validation_failed, "update race details", "Invalid input data");

        $name = $body->name;
        $managers = $body->race_manager;
        $pdo = $this->db->pdo();

        //Validate Race name
        if( empty($name) || !is_string($name) )
            return $this->responder->error($code_validation_failed, "update race details", "Invalid input data");

        // Validate managers array
        if( !is_array($managers) || count($managers) <= 0 )
            return $this->responder->error($code_validation_failed, "update race details", "Invalid input data");
        $admin_set = false;
        
        foreach( $managers as $manager ){
            $username = $manager->username;
            $is_admin = $manager->is_admin;

            // validate input data
            if( empty($username) || !is_string($username) || !intval($is_admin) )
                return $this->responder->error($code_validation_failed, "update race details", "Invalid input data");
            // check if user exists and is valid
            $result = $pdo->prepare("SELECT COUNT(*) FROM login WHERE login.username = :username");
            $result->bindParam(':username', $username, PDO::PARAM_STR);
            $result->execute();
            $recordcount = $result->fetchColumn();
            if($recordcount != 1)
                return $this->responder->error($code_validation_failed, "update race details", "Invalid input data");
            
            // check if user is admin and if admin is still set to true...
            if( $username == $_SESSION['user']['username'] && $is_admin )
                $admin_set = true;
        }
        if( !$admin_set )
            return $this->responder->error($code_validation_failed, "update race details", "Invalid input data");

        // start transaction
        if(!$pdo->beginTransaction()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to start transaction");

        // Update Race
        $result = $pdo->prepare("UPDATE race_overview SET race_overview.race_name = :race_name WHERE race_overview.race_id = :race_id");
        $result->bindParam(':race_name', $name, PDO::PARAM_STR);
        $result->bindParam(':race_id', $raceID, PDO::PARAM_INT);
        $result->execute();
        if( $result->rowCount() <= 0 ){
            $pdo->rollBack();
            return $this->responder->error($code_internal_error , "update race", "Failed to update race.");
        }

        // update race managers
        $result = $pdo->prepare("DELETE FROM race_relations WHERE race_relations.race_id = :race_id");
        $result->bindParam(':race_id', $raceID, PDO::PARAM_INT);
        $result->execute();
        
        // bindvalue is 1-indexed, so $k+1
        foreach( $managers as $manager ){
            // insert race manager
            $result = $pdo->prepare("INSERT INTO race_relations (login_id, race_id, is_admin) SELECT login.id, :race_id, :is_admin FROM login WHERE login.username = :username");
            $result->bindParam(':username', $manager->username, PDO::PARAM_STR);
            $result->bindParam(':race_id', $raceID, PDO::PARAM_INT);
            $result->bindParam(':is_admin', $manager->is_admin, PDO::PARAM_INT);
            $result->execute();
        }

        // commit transaction
        if(!$pdo->commit()) return $this->responder->error($code_internal_error , "Transaction failed", "Failed to commit transaction");

        // return
        return $this->responder->success(['result' => 'successful']);
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

}

?>