<?php
// RacegoController.php

class RacegoController
{

    private $router;
    private $responder;
    private $record_service;

    /**
     * @param Tqdev\PhpCrudApi\Middleware\Router\Router $router
     * @param Psr\Http\Message\ServerRequestInterface $responder
     * @param Tqdev\PhpCrudApi\Record\RecordService $record_service
     */
    public function __construct($router, $responder, $record_service)
    {
        $this->router = $router;
        $this->responder = $responder;
        $this->record_service = $record_service;

        $router->register('GET', '/hello_to_all', array($this, 'sayHello'));

        $router->register('GET', '/hello_to/*', array($this, 'sayHelloTo'));
        $router->register('GET', '/hello_to/', array($this, 'sayHelloTo'));

        $router->register('GET', '/hello_to_friend/*/*', array($this, 'sayHelloToFriend'));
        $router->register('GET', '/hello_to_friend/*/', array($this, 'sayHelloToFriend'));
        
        $router->register('POST', '/book/*/set_state', array($this, 'setBookState'));
    }

    /**
     * @param Psr\Http\Message\ServerRequestInterface $request
     */
    public function sayHello($request)
    {
        return $this->responder->success(['answer' => 'Hello All']);

    }

    /**
     * @param Psr\Http\Message\ServerRequestInterface $request
     */
    public function sayHelloTo($request)
    {
        $name = Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 2);
        if ($name) {
            return $this->responder->success([
                'answer' => sprintf('Hello %s', $name)
            ]);
        } else {
            return $this->responder->error(9999, 'You do not have a name.', [
                'name' => 'Name required (path)',
            ]);
        }
    }
    /**
     * @param Psr\Http\Message\ServerRequestInterface $request
     */
    public function sayHelloToFriend($request)
    {
        $name = Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 2);
        $friend = Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 3);
        if (!$friend) {
            return $this->responder->error(9999, sprintf('%s does not have a friend.', $name), [
                'friend' => 'Friend required (path)',
            ]);
        } else {
            return $this->responder->success([
                'answer' => sprintf('Hello %s, friend of %s.', $friend, $name)
            ]);
        }
    }
    
    /**
     * @param Psr\Http\Message\ServerRequestInterface $request
     */
    public function setBookState($request)
    {
        $code_record_not_found = Tqdev\PhpCrudApi\Record\ErrorCode::RECORD_NOT_FOUND;
        $code_validation_failed = Tqdev\PhpCrudApi\Record\ErrorCode::INPUT_VALIDATION_FAILED;
        $body = $request->getParsedBody();
        $id = (int)Tqdev\PhpCrudApi\RequestUtils::getPathSegment($request, 2);
        $new_state = property_exists($body, 'new_state') ? $body->new_state : '';

        if (!in_array($new_state, ['available', 'sold_out'])) {
            return $this->responder->error($code_validation_failed, 'set book state', [
                'new_state' => 'Value out of range',
            ]);
        }

        // Example how to get multiple records.
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

    }
    
}