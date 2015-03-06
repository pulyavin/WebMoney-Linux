SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- База данных: `webmoney`
--

-- --------------------------------------------------------

--
-- Структура таблицы `bl`
--

CREATE TABLE IF NOT EXISTS `bl` (
  `time` int(11) unsigned NOT NULL,
  `rank` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `events`
--

CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL,
  `time` int(10) unsigned NOT NULL,
  `is_hidden` tinyint(3) unsigned NOT NULL,
  `type` tinyint(3) unsigned NOT NULL,
  `description` text CHARACTER SET utf8 NOT NULL,
  `expiration` int(10) unsigned DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `invoices`
--

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int(10) unsigned NOT NULL,
  `orderid` int(10) unsigned DEFAULT NULL,
  `storewmid` varchar(12) CHARACTER SET utf8 NOT NULL,
  `storepurse` varchar(13) CHARACTER SET utf8 NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `datecrt` int(10) unsigned NOT NULL,
  `dateupd` int(10) unsigned NOT NULL,
  `state` tinyint(3) unsigned DEFAULT NULL,
  `address` text CHARACTER SET utf8,
  `description` text CHARACTER SET utf8,
  `period` tinyint(3) unsigned DEFAULT NULL,
  `expiration` tinyint(3) unsigned DEFAULT NULL,
  `wmtranid` int(10) unsigned DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `outvoices`
--

CREATE TABLE IF NOT EXISTS `outvoices` (
  `id` int(10) unsigned NOT NULL,
  `orderid` int(10) unsigned DEFAULT NULL,
  `storepurse` varchar(13) NOT NULL,
  `customerwmid` varchar(12) NOT NULL,
  `customerpurse` varchar(13) NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `datecrt` int(10) unsigned NOT NULL,
  `dateupd` int(10) unsigned NOT NULL,
  `state` tinyint(3) unsigned DEFAULT NULL,
  `address` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `period` tinyint(3) unsigned DEFAULT NULL,
  `expiration` tinyint(3) unsigned DEFAULT NULL,
  `wmtranid` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `purses`
--

CREATE TABLE IF NOT EXISTS `purses` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `pursename` varchar(13) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `amount` decimal(8,2) unsigned NOT NULL,
  `amount_last` decimal(8,2) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Структура таблицы `purses_times`
--

CREATE TABLE IF NOT EXISTS `purses_times` (
  `pursename` varchar(13) NOT NULL,
  `time` int(10) unsigned NOT NULL DEFAULT '0',
  `xml_id` tinyint(3) unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `system`
--

CREATE TABLE IF NOT EXISTS `system` (
  `name` varchar(15) NOT NULL,
  `value` text,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `tl`
--

CREATE TABLE IF NOT EXISTS `tl` (
  `time` int(11) unsigned NOT NULL,
  `rank` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `transactions`
--

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(10) unsigned NOT NULL,
  `pursesrc` varchar(13) NOT NULL,
  `pursedest` varchar(13) NOT NULL,
  `purse` varchar(13) NOT NULL,
  `corrpurse` varchar(13) NOT NULL,
  `type` enum('in','out') NOT NULL,
  `amount` decimal(8,2) unsigned NOT NULL,
  `comiss` decimal(6,2) unsigned NOT NULL,
  `opertype` tinyint(3) unsigned NOT NULL,
  `wminvid` int(10) unsigned DEFAULT NULL,
  `orderid` int(10) unsigned DEFAULT NULL,
  `tranid` int(10) unsigned DEFAULT NULL,
  `period` tinyint(3) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `datecrt` int(10) unsigned NOT NULL,
  `dateupd` int(10) unsigned NOT NULL,
  `corrwm` varchar(13) NOT NULL,
  `rest` decimal(8,2) unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Структура таблицы `userinfo`
--

CREATE TABLE IF NOT EXISTS `userinfo` (
  `name` varchar(15) NOT NULL,
  `value` text,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
