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
        $router->register('PUT', '/v1/set_user', array($this, 'putUser'));
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
        $stmt->execute();

        $record = $stmt->fetchAll() ?: null;
        if ($record === null) {
            return $this->responder->success([]);
        }
        return $this->responder->success($record);
    }

    public function putUser(ServerRequestInterface $request)
    {
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
    
    /* 
    public function setBookState($request)
    {
        $code_record_not_found = Tqdev\PhpCrudApi\Record\ErrorCode::RECORD_NOT_FOUND;
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $body = $request->getParsedBody();
        $id = (int)Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 2);
        $new_state = property_exists($body, 'new_state') ? $body->new_state : '';
        $id = property_exists($body, 'id') ? $body->id : 0;
        
        if(id <= 0)
        return $this->responder->error($code_record_not_found);
        else return $this->responder->success(['answer' => 'Erfolgreich :)']);
 */
/*         // Example how to get multiple records.
        $data = $this->record_service->_list('book', ['filter'=>['author,eq,Adrian']]);
        $books = $data->getRecords();
        
        // Example how to read one record
        $book = $this->record_service->read('book', $id, []);
        if (!$book) {
            return $this->responder->error($code_record_not_found, $id);
        }
        $record = (object)[
            'state' => 'new_state'
        ];
        return $this->responder->success($this->record_service->update( 'book', $id, $record, []));

    } */

}