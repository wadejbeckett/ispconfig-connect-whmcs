-- Tables the harness needs beyond the four copied WHMCS schemas. Column sets
-- cover what WHMCS stores for a server-module product; types follow WHMCS.
DROP TABLE IF EXISTS `tblproducts`;
CREATE TABLE `tblproducts` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `type` text COLLATE utf8_unicode_ci NOT NULL,
  `gid` int(10) NOT NULL DEFAULT '0',
  `name` text COLLATE utf8_unicode_ci NOT NULL,
  `servertype` text COLLATE utf8_unicode_ci NOT NULL,
  `servergroup` int(10) NOT NULL DEFAULT '0',
  `configoption1` text COLLATE utf8_unicode_ci NOT NULL, `configoption2` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption3` text COLLATE utf8_unicode_ci NOT NULL, `configoption4` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption5` text COLLATE utf8_unicode_ci NOT NULL, `configoption6` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption7` text COLLATE utf8_unicode_ci NOT NULL, `configoption8` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption9` text COLLATE utf8_unicode_ci NOT NULL, `configoption10` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption11` text COLLATE utf8_unicode_ci NOT NULL, `configoption12` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption13` text COLLATE utf8_unicode_ci NOT NULL, `configoption14` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption15` text COLLATE utf8_unicode_ci NOT NULL, `configoption16` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption17` text COLLATE utf8_unicode_ci NOT NULL, `configoption18` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption19` text COLLATE utf8_unicode_ci NOT NULL, `configoption20` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption21` text COLLATE utf8_unicode_ci NOT NULL, `configoption22` text COLLATE utf8_unicode_ci NOT NULL,
  `configoption23` text COLLATE utf8_unicode_ci NOT NULL, `configoption24` text COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `tblmodulelog`;
CREATE TABLE `tblmodulelog` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `module` text COLLATE utf8_unicode_ci NOT NULL,
  `action` text COLLATE utf8_unicode_ci NOT NULL,
  `request` longtext COLLATE utf8_unicode_ci NOT NULL,
  `response` longtext COLLATE utf8_unicode_ci NOT NULL,
  `arrdata` longtext COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `tblservers_remote`;
CREATE TABLE `tblservers_remote` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `server_id` int(10) unsigned NOT NULL,
  `num_accounts` int(10) unsigned NOT NULL DEFAULT '0',
  `meta_data` text COLLATE utf8_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
