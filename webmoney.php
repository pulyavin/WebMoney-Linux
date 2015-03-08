#!/usr/bin/env php
<?php
require_once("commands.php");
require_once("shell.php");

// парсим файл конфигурации
$config = parse_ini_file("config.ini", true);

// подгружаем библиотеку wmxml
require_once($config['vendors']['wmxml']);

// подгружаем библиотеку console
require_once($config['vendors']['console']);

// подгружаем библиотеку php-wmsigner
require_once($config['vendors']['signer']);

use \pulyavin\wmxml as wmxml;
use \baibaratsky\WebMoney\Signer as Signer;

try {
	// будем работать с классом Signer на PHP
	if (
		isset($config['main']['key_file'])
		&&
		isset($config['main']['key_password'])
	) {
		$wmsigner = new Signer($config['main']['wmid'], $config['main']['key_file'], $config['main']['key_password']);
	}
	// бинарный подписчик
	else {
		$wmsigner = $config['paths']['wmsigner'];
	}

	// создаём объект WebMoney XML API
	$wmxml = new wmxml(
		"classic",
		[
			"wmid"     => $config['main']['wmid'],
			"wmsigner" => $wmsigner,
			"rootca"   => $config['paths']['rootca'],
			"tranid"  => $config['paths']['tranid'],
			"connect"  => $config['curl']['connect'],
			"timeout"  => $config['curl']['timeout']
		]
	);

	// создаём объект PDO
	$pdo = new PDO(
		$config['pdo']['dsn'],
		$config['pdo']['user'],
		$config['pdo']['password'],
		[
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);
	if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) == "mysql") {
		$pdo->exec("SET NAMES UTF8");
	}

	// создаём объект ядра комманд
	$commands = new commands($wmxml, $pdo);

	//создаём объект консоли
	$console = new console();

	// запускаем shell
	shell::init($commands, $console)->start();
} catch (Exception $e) {
	echo $e->getMessage();
}
?>