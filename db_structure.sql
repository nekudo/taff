--
-- Table structure for table `accounts`
--

CREATE TABLE IF NOT EXISTS `accounts` (
  `id_account` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(30) NOT NULL,
  `password` varchar(30) NOT NULL,
  `language_code` varchar(50) NOT NULL DEFAULT 'en',
  `oauth_consumer_key` varchar(100) NOT NULL,
  `oauth_consumer_secret` varchar(100) NOT NULL,
  `oauth_access_key` varchar(100) NOT NULL,
  `oauth_access_secret` varchar(100) NOT NULL,
  PRIMARY KEY (`id_account`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `account_follows`
--

CREATE TABLE IF NOT EXISTS `account_follows` (
  `id_account` int(10) unsigned NOT NULL,
  `id_nick` int(10) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unfollowed` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id_account`,`id_nick`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `account_x_content`
--

CREATE TABLE IF NOT EXISTS `account_x_content` (
  `id_account` int(10) unsigned NOT NULL,
  `id_contentsource` int(10) unsigned NOT NULL,
  `id_keyword` int(10) unsigned NOT NULL,
  `last_pubdate` int(11) NOT NULL,
  PRIMARY KEY (`id_account`,`id_contentsource`,`id_keyword`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `account_x_keyword`
--

CREATE TABLE IF NOT EXISTS `account_x_keyword` (
  `id_account` int(10) unsigned NOT NULL,
  `id_keyword` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_account`,`id_keyword`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `contentsources`
--

CREATE TABLE IF NOT EXISTS `contentsources` (
  `id_contentsource` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL,
  `type` varchar(30) NOT NULL,
  PRIMARY KEY (`id_contentsource`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `keywords`
--

CREATE TABLE IF NOT EXISTS `keywords` (
  `id_keyword` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `keyword` varchar(100) NOT NULL,
  PRIMARY KEY (`id_keyword`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `twitter_nicks`
--

CREATE TABLE IF NOT EXISTS `twitter_nicks` (
  `id_nick` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nickname` varchar(30) NOT NULL,
  PRIMARY KEY (`id_nick`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;