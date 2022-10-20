<?php


/**
 * Please enter your DB and API config settings below
 * 
 **/


use Tqdev\PhpCrudApi\Config;

include 'RacegoController.php';
include 'RaceManageController.php';

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
        'customControllers' => 'RacegoController, RaceManageController',
        
        // auth settings
        'middlewares' => 'dbAuth,authorization',
        'authorization.tableHandler' => function ($operation, $tableName) {
            return $tableName != 'login';
        },
        'dbAuth.sessionName' => 'TOKEN',
        'dbAuth.returnedColumns' => 'id, username',
        'dbAuth.registerUser' => '1',   // disable registration of new user
        'dbAuth.loginAfterRegistration' => '1',     // enable login after registration
        'dbAuth.usersTable' => 'login',
        'dbAuth.usernameColumn' => 'username',
        'dbAuth.passwordColumn' => 'password',
        'dbAuth.passwordLength' => '8',

    ]
);