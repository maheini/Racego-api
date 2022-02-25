<?php


/**
 * Please enter your DB and API config settings below
 * 
 **/


use Tqdev\PhpCrudApi\Config;

include 'RacegoController.php';

$config = new Config([
        // debugging
        // 'debug' => true,

        // TODO: set server setting below 
        //^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
        // 'address' => 'localhost',
        // 'port' => '3306',
        'driver' => 'mysql',
        'username' => 'root',
        'password' => '',
        'database' => 'racego',

        // controller settings
        'controllers' => '',
        'customControllers' => 'RacegoController',
        
        // auth settings
        'middlewares' => 'cors,dbAuth,authorization',
        'authorization.tableHandler' => function ($operation, $tableName) {
            return $tableName != 'users';
        },
        'dbAuth.sessionName' => 'TOKEN',
        'dbAuth.returnedColumns' => 'username',
        'dbAuth.registerUser' => '0',   // disable registration of new user
        'dbAuth.usersTable' => 'login',
        'dbAuth.usernameColumn' => 'username',
        'dbAuth.passwordColumn' => 'password',
        'dbAuth.passwordLength' => '8',

    ]
);