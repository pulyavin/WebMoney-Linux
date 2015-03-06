#!/usr/bin/env php
<?php
require_once("commands.php");
require_once("shell.php");

# парсим файл конфигурации
$config = parse_ini_file("config.ini", true);

# подгружаем библиотеку wmxml 
require_once($config['vendors']['wmxml']);

# подгружаем библиотеку console 
require_once($config['vendors']['console']);

use \pulyavin\wmxml as wmxml;

# создаём объект WebMoney XML API
$wmxml = new wmxml(
	"classic",
	[
		"wmid" => $config['main']['wmid'],
		"wmsigner" => $config['paths']['wmsigner'],
		"rootca" => $config['paths']['rootca'],
		"transid" => $config['paths']['transid'],
		"connect" => $config['curl']['connect'],
		"timeout" => $config['curl']['timeout']
	]
);

# создаём объект PDO
$pdo = new PDO(
	$config['pdo']['dsn'],
	$config['pdo']['user'],
	$config['pdo']['password'],
	[
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
	]
);
$pdo->exec("SET NAMES UTF8");

# создаём объект ядра комманд
$commands = new commands($wmxml, $pdo);

# создаём объект консоли
$console = new console();

# запускаем shell
shell::init($commands, $console)->start();
?>