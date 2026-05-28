/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: yukleme_plani_staging
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `account_files`
--

DROP TABLE IF EXISTS `account_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL DEFAULT '',
  `file_type` varchar(50) NOT NULL DEFAULT '',
  `file_size` int(11) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tid` (`transaction_id`),
  CONSTRAINT `fk_af_tid` FOREIGN KEY (`transaction_id`) REFERENCES `account_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_files`
--

LOCK TABLES `account_files` WRITE;
/*!40000 ALTER TABLE `account_files` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_transactions`
--

DROP TABLE IF EXISTS `account_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `transaction_time` time NOT NULL DEFAULT '00:00:00',
  `type` enum('gelir','gider','havale','nakit') NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT '',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(5) NOT NULL DEFAULT 'TRY',
  `payment_method` varchar(30) NOT NULL DEFAULT 'nakit',
  `person_company` varchar(200) NOT NULL DEFAULT '',
  `description` text NOT NULL DEFAULT '',
  `document_no` varchar(100) NOT NULL DEFAULT '',
  `has_invoice` tinyint(1) NOT NULL DEFAULT 0,
  `is_for_company` tinyint(1) NOT NULL DEFAULT 1,
  `is_given_to_accountant` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text NOT NULL DEFAULT '',
  `has_files` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_date` (`transaction_date`),
  KEY `idx_type` (`type`),
  KEY `idx_accountant` (`is_given_to_accountant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_transactions`
--

LOCK TABLES `account_transactions` WRITE;
/*!40000 ALTER TABLE `account_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dev_notes`
--

DROP TABLE IF EXISTS `dev_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dev_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_url` varchar(255) NOT NULL DEFAULT '',
  `page_name` varchar(100) NOT NULL DEFAULT '',
  `note` text NOT NULL,
  `done` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dev_notes`
--

LOCK TABLES `dev_notes` WRITE;
/*!40000 ALTER TABLE `dev_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `dev_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kantar_fisleri`
--

DROP TABLE IF EXISTS `kantar_fisleri`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kantar_fisleri` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fis_no` varchar(50) NOT NULL DEFAULT '',
  `giris_tarih` varchar(40) NOT NULL DEFAULT '',
  `cikis_tarih` varchar(40) NOT NULL DEFAULT '',
  `plaka` varchar(30) NOT NULL DEFAULT '',
  `firma_adi` varchar(120) NOT NULL DEFAULT '',
  `malin_cinsi` varchar(200) NOT NULL DEFAULT '',
  `geldigi_yer` varchar(200) NOT NULL DEFAULT '',
  `gittigi_yer` varchar(100) NOT NULL DEFAULT '',
  `aciklama` text DEFAULT NULL,
  `operator_adi` varchar(100) NOT NULL DEFAULT '',
  `tartim1` decimal(12,3) NOT NULL DEFAULT 0.000,
  `alibi1` varchar(30) NOT NULL DEFAULT '',
  `tartim2` decimal(12,3) NOT NULL DEFAULT 0.000,
  `alibi2` varchar(30) NOT NULL DEFAULT '',
  `net_kg` decimal(12,3) NOT NULL DEFAULT 0.000,
  `toplam_palet` int(11) NOT NULL DEFAULT 0,
  `kasa_dara` decimal(10,3) NOT NULL DEFAULT 0.000,
  `palet_dara` decimal(10,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `aciklama2` text DEFAULT NULL,
  `palet_sayisi` int(11) NOT NULL DEFAULT 0,
  `kasa_cinsi` varchar(200) NOT NULL DEFAULT '',
  `kasa_sayisi` int(11) NOT NULL DEFAULT 0,
  `palet_cinsi` varchar(200) NOT NULL DEFAULT '',
  `foto_data` mediumtext DEFAULT NULL,
  `depo` varchar(150) NOT NULL DEFAULT '',
  `parti_no` varchar(80) NOT NULL DEFAULT '',
  `kasa_dara_total` decimal(12,3) NOT NULL DEFAULT 0.000,
  `palet_dara_total` decimal(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `idx_kf_firma` (`firma_adi`),
  KEY `idx_kf_malin` (`malin_cinsi`(100)),
  KEY `idx_kf_tarih` (`giris_tarih`),
  KEY `idx_kf_depo` (`depo`(80)),
  KEY `idx_kf_parti_no` (`parti_no`(40))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kantar_fisleri`
--

LOCK TABLES `kantar_fisleri` WRITE;
/*!40000 ALTER TABLE `kantar_fisleri` DISABLE KEYS */;
/*!40000 ALTER TABLE `kantar_fisleri` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kantar_gruplar`
--

DROP TABLE IF EXISTS `kantar_gruplar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kantar_gruplar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fis_id` int(11) NOT NULL,
  `sira` int(11) NOT NULL DEFAULT 0,
  `grup_adi` varchar(100) NOT NULL DEFAULT '',
  `palet_sayisi` int(11) NOT NULL DEFAULT 0,
  `kasa_adedi` int(11) NOT NULL DEFAULT 0,
  `kasa_dara_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `palet_dara_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `brut_kg` decimal(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `fis_id` (`fis_id`),
  CONSTRAINT `kantar_gruplar_ibfk_1` FOREIGN KEY (`fis_id`) REFERENCES `kantar_fisleri` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kantar_gruplar`
--

LOCK TABLES `kantar_gruplar` WRITE;
/*!40000 ALTER TABLE `kantar_gruplar` DISABLE KEYS */;
/*!40000 ALTER TABLE `kantar_gruplar` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kantar_kasa_palet_satir`
--

DROP TABLE IF EXISTS `kantar_kasa_palet_satir`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kantar_kasa_palet_satir` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fis_id` int(11) NOT NULL,
  `tip` varchar(10) NOT NULL DEFAULT 'kasa',
  `cinsi` varchar(150) NOT NULL DEFAULT '',
  `sayisi` int(11) NOT NULL DEFAULT 0,
  `birim_dara_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `idx_kps_fis` (`fis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kantar_kasa_palet_satir`
--

LOCK TABLES `kantar_kasa_palet_satir` WRITE;
/*!40000 ALTER TABLE `kantar_kasa_palet_satir` DISABLE KEYS */;
/*!40000 ALTER TABLE `kantar_kasa_palet_satir` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loading_pallets`
--

DROP TABLE IF EXISTS `loading_pallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loading_pallets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loading_record_id` int(11) NOT NULL,
  `palet_no` varchar(40) NOT NULL DEFAULT '',
  `kasa_adeti` int(11) NOT NULL DEFAULT 0,
  `size` varchar(60) NOT NULL DEFAULT '',
  `brut_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `dara_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `net_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `kasa_cinsi_id` int(11) DEFAULT NULL,
  `palet_tipi_id` int(11) DEFAULT NULL,
  `urun_cinsi` varchar(150) NOT NULL DEFAULT '',
  `depo` varchar(150) NOT NULL DEFAULT '',
  `sira_no` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `islendi` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_pallet_kasa` (`kasa_cinsi_id`),
  KEY `fk_pallet_palet` (`palet_tipi_id`),
  KEY `idx_pallet_record` (`loading_record_id`),
  KEY `idx_lp_depo` (`depo`(80)),
  KEY `idx_lp_urun_cinsi` (`urun_cinsi`(80)),
  CONSTRAINT `fk_pallet_kasa` FOREIGN KEY (`kasa_cinsi_id`) REFERENCES `material_definitions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pallet_palet` FOREIGN KEY (`palet_tipi_id`) REFERENCES `material_definitions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pallet_record` FOREIGN KEY (`loading_record_id`) REFERENCES `loading_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loading_pallets`
--

LOCK TABLES `loading_pallets` WRITE;
/*!40000 ALTER TABLE `loading_pallets` DISABLE KEYS */;
INSERT INTO `loading_pallets` VALUES
(1,1,'2',36,'',1000.000,102.000,898.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(2,1,'3',36,'',715.000,102.000,613.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(3,1,'4',36,'',1000.000,102.000,898.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(4,1,'5',21,'',607.000,72.000,535.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(5,2,'6',30,'',745.000,90.000,655.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(6,2,'7',30,'',681.000,90.000,591.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(7,2,'8',20,'',448.000,70.000,378.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(8,2,'9',30,'',669.000,90.000,579.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(9,2,'10',16,'',387.000,62.000,325.000,23,6,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(10,2,'11',21,'',476.000,72.000,404.000,23,6,'','Karaman Cihat',5,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(11,2,'12',30,'',738.000,90.000,648.000,23,6,'','Karaman Cihat',6,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(12,3,'13',59,'',574.000,46.320,527.680,22,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(13,4,'14',36,'',826.000,102.000,724.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(14,4,'15',24,'',564.000,78.000,486.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(15,5,'16',57,'',594.000,45.360,548.640,22,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(16,6,'17',29,'',620.000,88.000,532.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(17,6,'18',34,'',724.000,98.000,626.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(18,7,'19',100,'',1033.000,66.000,967.000,22,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(19,7,'20',14,'',144.000,24.720,119.280,22,21,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(20,8,'21',36,'',710.000,102.000,608.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(21,8,'22',36,'',803.000,102.000,701.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(22,8,'23',36,'',780.000,102.000,678.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(23,8,'24',36,'',790.000,102.000,688.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(24,8,'25',36,'',787.000,102.000,685.000,23,6,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(25,8,'26',42,'',851.000,114.000,737.000,23,6,'','Karaman Cihat',5,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(26,8,'27',28,'',628.000,86.000,542.000,23,6,'','Karaman Cihat',6,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(27,9,'28',100,'',998.000,66.000,932.000,22,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(28,9,'29',100,'',995.000,66.000,929.000,22,21,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(29,9,'30',100,'',997.000,66.000,931.000,22,21,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(30,9,'36',28,'',282.000,31.440,250.560,22,21,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(31,10,'31',36,'',785.000,102.000,683.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(32,10,'32',36,'',764.000,102.000,662.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(33,10,'33',36,'',788.000,102.000,686.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(34,10,'34',36,'',777.000,102.000,675.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(35,10,'35',42,'',858.000,114.000,744.000,23,6,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(36,11,'37',37,'',735.000,104.000,631.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(37,11,'38',36,'',710.000,102.000,608.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(38,11,'39',30,'',666.000,90.000,576.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(39,11,'40',32,'',655.000,94.000,561.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(40,11,'41',24,'',540.000,78.000,462.000,23,6,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(41,11,'42',19,'',391.000,68.000,323.000,23,6,'','Karaman Cihat',5,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(42,11,'43',36,'',905.000,102.000,803.000,23,6,'','Karaman Cihat',6,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(43,12,'44',18,'',479.000,66.000,413.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(44,12,'45',100,'',1033.000,66.000,967.000,22,21,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(45,12,'52',75,'',777.000,54.000,723.000,22,21,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(46,12,'53',14,'',364.000,58.000,306.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(47,13,'46',36,'',707.000,102.000,605.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(48,13,'47',30,'',690.000,90.000,600.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(49,13,'48',34,'',706.000,98.000,608.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(50,13,'49',24,'',593.000,78.000,515.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(51,13,'50',21,'',455.000,72.000,383.000,23,6,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(52,13,'51',32,'',679.000,94.000,585.000,23,6,'','Karaman Cihat',5,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(53,14,'54',36,'',784.000,102.000,682.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(54,14,'55',36,'',749.000,102.000,647.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(55,14,'56',30,'',635.000,90.000,545.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(56,14,'57',36,'',740.000,102.000,638.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(57,14,'58',36,'',776.000,102.000,674.000,23,6,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(58,14,'59',30,'',626.000,90.000,536.000,23,6,'','Karaman Cihat',5,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(59,14,'60',36,'',775.000,102.000,673.000,23,6,'','Karaman Cihat',6,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(60,14,'61',23,'',564.000,76.000,488.000,23,6,'','Karaman Cihat',7,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(61,14,'62',17,'',367.000,64.000,303.000,23,6,'','Karaman Cihat',8,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(62,14,'63',21,'',461.000,72.000,389.000,23,6,'','Karaman Cihat',9,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(63,14,'64',9,'',200.000,48.000,152.000,23,6,'','Karaman Cihat',10,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(64,14,'65',30,'',676.000,90.000,586.000,23,6,'','Karaman Cihat',11,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(65,14,'68',30,'',638.000,90.000,548.000,23,6,'','Karaman Cihat',12,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(66,14,'69',30,'',677.000,90.000,587.000,23,6,'','Karaman Cihat',13,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(67,14,'70',30,'',663.000,90.000,573.000,23,6,'','Karaman Cihat',14,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(68,14,'71',34,'',743.000,98.000,645.000,23,6,'','Karaman Cihat',15,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(69,14,'72',31,'',688.000,92.000,596.000,23,6,'','Karaman Cihat',16,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(70,14,'73',15,'',358.000,60.000,298.000,23,6,'','Karaman Cihat',17,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(71,14,'74',21,'',475.000,72.000,403.000,23,6,'','Karaman Cihat',18,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(72,15,'66',23,'',586.000,76.000,510.000,23,6,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(73,15,'67',13,'',317.000,56.000,261.000,23,6,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(74,15,'75',30,'',698.000,90.000,608.000,23,6,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(75,15,'76',30,'',690.000,90.000,600.000,23,6,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(76,15,'77',18,'',367.000,66.000,301.000,23,6,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(77,16,'78',100,'',1064.000,68.000,996.000,24,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(78,16,'79',100,'',1076.000,68.000,1008.000,24,21,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(79,16,'80',100,'',1041.000,68.000,973.000,24,21,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(80,16,'81',100,'',1048.000,68.000,980.000,24,21,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(81,17,'82',100,'',1038.000,66.000,972.000,22,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(82,18,'83',100,'',1053.000,68.000,985.000,24,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(83,18,'84',100,'',1054.000,68.000,986.000,24,21,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(84,18,'85',100,'',1020.000,68.000,952.000,24,21,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(85,18,'86',100,'',1060.000,68.000,992.000,24,21,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(86,18,'87',100,'',1049.000,68.000,981.000,24,21,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(87,18,'88',100,'',1063.000,68.000,995.000,24,21,'','Karaman Cihat',5,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(88,18,'89',100,'',1058.000,68.000,990.000,24,21,'','Karaman Cihat',6,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(89,18,'90',100,'',1031.000,68.000,963.000,24,21,'','Karaman Cihat',7,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(90,18,'91',100,'',1030.000,68.000,962.000,24,21,'','Karaman Cihat',8,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(91,18,'92',100,'',1036.000,68.000,968.000,24,21,'','Karaman Cihat',9,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(92,18,'93',100,'',1021.000,68.000,953.000,24,21,'','Karaman Cihat',10,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(93,18,'94',100,'',1011.000,68.000,943.000,24,21,'','Karaman Cihat',11,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(94,18,'95',100,'',1017.000,68.000,949.000,24,21,'','Karaman Cihat',12,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(95,19,'96',100,'',1004.000,68.000,936.000,24,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(96,19,'98',100,'',1014.000,68.000,946.000,24,21,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(97,20,'97',100,'',1033.000,68.000,965.000,24,21,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(98,20,'99',100,'',1102.000,68.000,1034.000,24,21,'','Karaman Cihat',1,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(99,20,'100',100,'',1103.000,68.000,1035.000,24,21,'','Karaman Cihat',2,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(100,20,'101',100,'',1110.000,68.000,1042.000,24,21,'','Karaman Cihat',3,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(101,20,'102',100,'',1109.000,68.000,1041.000,24,21,'','Karaman Cihat',4,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(102,20,'103',100,'',1084.000,68.000,1016.000,24,21,'','Karaman Cihat',5,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(103,20,'104',100,'',1094.000,68.000,1026.000,24,21,'','Karaman Cihat',6,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(104,20,'105',100,'',1098.000,68.000,1030.000,24,21,'','Karaman Cihat',7,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(105,20,'106',100,'',1066.000,68.000,998.000,24,21,'','Karaman Cihat',8,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(106,20,'107',100,'',1012.000,68.000,944.000,24,21,'','Karaman Cihat',9,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(107,20,'108',100,'',1019.000,68.000,951.000,24,21,'','Karaman Cihat',10,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(108,20,'109',100,'',1036.000,68.000,968.000,24,21,'','Karaman Cihat',11,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(109,20,'110',100,'',1051.000,68.000,983.000,24,21,'','Karaman Cihat',12,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(110,20,'111',110,'',1150.000,73.000,1077.000,24,21,'','Karaman Cihat',13,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(111,20,'112',110,'',1157.000,73.000,1084.000,24,21,'','Karaman Cihat',14,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(112,20,'113',100,'',1038.000,68.000,970.000,24,21,'','Karaman Cihat',15,'2026-05-20 19:31:51','2026-05-20 19:31:51',0),
(113,21,'114',158,'',3643.000,316.000,3327.000,23,NULL,'','Karaman Cihat',0,'2026-05-20 19:31:51','2026-05-20 19:31:51',0);
/*!40000 ALTER TABLE `loading_pallets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loading_records`
--

DROP TABLE IF EXISTS `loading_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loading_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firma` varchar(150) NOT NULL DEFAULT '',
  `bolge` varchar(150) NOT NULL DEFAULT '',
  `parti_no` varchar(80) NOT NULL DEFAULT '',
  `gumruk` varchar(150) NOT NULL DEFAULT '',
  `nakliye_bedeli` decimal(12,2) NOT NULL DEFAULT 0.00,
  `avans` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sofor_adi` varchar(150) NOT NULL DEFAULT '',
  `fatura_no` varchar(80) NOT NULL DEFAULT '',
  `casus_no` varchar(80) NOT NULL DEFAULT '',
  `on_plaka` varchar(30) NOT NULL DEFAULT '',
  `arka_plaka` varchar(30) NOT NULL DEFAULT '',
  `nakliye_sirketi` varchar(150) NOT NULL DEFAULT '',
  `telefon` varchar(40) NOT NULL DEFAULT '',
  `type` varchar(20) NOT NULL DEFAULT 'yukleme',
  `tarih` date DEFAULT NULL,
  `alici` varchar(150) NOT NULL DEFAULT '',
  `urun` varchar(150) NOT NULL DEFAULT '',
  `etiket` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `durum` varchar(20) NOT NULL DEFAULT '',
  `cikis_nedeni` varchar(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_tarih` (`tarih`),
  KEY `idx_firma` (`firma`),
  KEY `idx_parti` (`parti_no`),
  KEY `idx_lr_type` (`type`),
  KEY `idx_type` (`type`),
  KEY `idx_tarih_type` (`tarih`,`type`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loading_records`
--

LOCK TABLES `loading_records` WRITE;
/*!40000 ALTER TABLE `loading_records` DISABLE KEYS */;
INSERT INTO `loading_records` VALUES
(1,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-03','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(2,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-06','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(3,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-06','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(4,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-07','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(5,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-07','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(6,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-08','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(7,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-08','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(8,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-09','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(9,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-10','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(10,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-10','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(11,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-11','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(12,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-12','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(13,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-12','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(14,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-13','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(15,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-13','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(16,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-15','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(17,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-16','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(18,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-16','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(19,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-18','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','KÜÇÜK BOY (2.)'),
(20,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-18','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','ÇIKMA'),
(21,'Karaman Cihat','','','',0.00,0.00,'','IMPORT_CIKMALAR_3','','','','','','cikma','2026-05-18','','','','2026-05-20 19:31:51','2026-05-20 19:31:51','','MEYSU');
/*!40000 ALTER TABLE `loading_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_definitions`
--

DROP TABLE IF EXISTS `material_definitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit_dara_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_definitions`
--

LOCK TABLES `material_definitions` WRITE;
/*!40000 ALTER TABLE `material_definitions` DISABLE KEYS */;
INSERT INTO `material_definitions` VALUES
(1,'kasa_cinsi','Plastik Kasa (Standart)',1.800,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(2,'kasa_cinsi','Plastik Kasa (BÃ¼yÃ¼k)',2.100,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(3,'kasa_cinsi','Karton Kasa',0.350,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(4,'kasa_cinsi','Tahta Kasa',2.500,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(5,'palet_tipi','Euro Palet',25.000,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(6,'palet_tipi','Standart Palet',22.000,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(7,'palet_tipi','Tek KullanÄ±mlÄ±k Palet',18.000,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(8,'sapka','Karton Åžapka',0.300,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(9,'kosebent','Plastik KÃ¶ÅŸebent (4 lÃ¼)',0.400,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(10,'serit','PP Åžerit',0.150,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(11,'casus','Casus Tel',0.200,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(12,'kasa_etiketi','Standart Etiket',0.050,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(13,'minti','Minti',0.100,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(14,'kenar_kartonu','Kenar Kartonu',0.250,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(15,'taban_kagidi','Taban KaÄŸÄ±dÄ±',0.150,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(16,'sale','Åžale',0.200,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(17,'viyol','Viyol',0.300,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(18,'kose_karton','KÃ¶ÅŸe Karton',0.180,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(19,'kraft_kagit','Kraft KaÄŸÄ±t',0.120,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(20,'file','File',0.080,1,'2026-05-20 19:31:38','2026-05-20 19:31:38'),
(21,'palet_tipi','İhracat Palet',18.000,1,'2026-05-20 19:31:51','2026-05-20 19:31:51'),
(22,'kasa_cinsi','C-10 Mavi',0.480,1,'2026-05-20 19:31:51','2026-05-20 19:31:51'),
(23,'kasa_cinsi','Plastik (K-65)',2.000,1,'2026-05-20 19:31:51','2026-05-20 19:31:51'),
(24,'kasa_cinsi','Ayaklı Kasa',0.500,1,'2026-05-20 19:31:51','2026-05-20 19:31:51'),
(25,'firma','Karaman Cihat',0.000,1,'2026-05-20 19:31:51','2026-05-20 19:31:51');
/*!40000 ALTER TABLE `material_definitions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_stock_movements`
--

DROP TABLE IF EXISTS `material_stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_stock_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `movement_date` date NOT NULL,
  `movement_type` enum('giris','sevk','kullanim','duzeltme') NOT NULL DEFAULT 'giris',
  `material_id` int(11) DEFAULT NULL,
  `material_name` varchar(200) NOT NULL DEFAULT '',
  `material_type` varchar(50) NOT NULL DEFAULT '',
  `depo` varchar(150) NOT NULL DEFAULT '',
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit` varchar(20) NOT NULL DEFAULT 'adet',
  `unit_dara_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `total_dara_kg` decimal(12,3) NOT NULL DEFAULT 0.000,
  `source_type` varchar(30) NOT NULL DEFAULT '',
  `source_id` int(11) DEFAULT NULL,
  `source_detail_id` int(11) DEFAULT NULL,
  `belge_no` varchar(100) NOT NULL DEFAULT '',
  `firma` varchar(200) NOT NULL DEFAULT '',
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_msm_date` (`movement_date`),
  KEY `idx_msm_type` (`movement_type`),
  KEY `idx_msm_mat` (`material_id`),
  KEY `idx_msm_matname` (`material_name`(100)),
  KEY `idx_msm_mattype` (`material_type`(30)),
  KEY `idx_msm_depo` (`depo`(80)),
  KEY `idx_msm_source` (`source_type`,`source_id`),
  KEY `idx_source` (`source_type`,`source_id`),
  KEY `idx_material_id` (`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_stock_movements`
--

LOCK TABLES `material_stock_movements` WRITE;
/*!40000 ALTER TABLE `material_stock_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_template_items`
--

DROP TABLE IF EXISTS `material_template_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_template_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
  PRIMARY KEY (`id`),
  KEY `fk_mti_tpl` (`template_id`),
  KEY `fk_mti_mat` (`material_id`),
  CONSTRAINT `fk_mti_mat` FOREIGN KEY (`material_id`) REFERENCES `material_definitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mti_tpl` FOREIGN KEY (`template_id`) REFERENCES `material_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_template_items`
--

LOCK TABLES `material_template_items` WRITE;
/*!40000 ALTER TABLE `material_template_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_template_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_templates`
--

DROP TABLE IF EXISTS `material_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_templates`
--

LOCK TABLES `material_templates` WRITE;
/*!40000 ALTER TABLE `material_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `normalize_migration_queue`
--

DROP TABLE IF EXISTS `normalize_migration_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `normalize_migration_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_id` int(11) NOT NULL,
  `mat_type` varchar(40) NOT NULL DEFAULT '',
  `current_value` varchar(500) NOT NULL DEFAULT '',
  `v2_value` varchar(500) NOT NULL DEFAULT '',
  `will_change` tinyint(1) NOT NULL DEFAULT 0,
  `is_merge` tinyint(1) NOT NULL DEFAULT 0,
  `is_survivor` tinyint(1) NOT NULL DEFAULT 0,
  `surviving_id` int(11) DEFAULT NULL,
  `dup_id` int(11) DEFAULT NULL,
  `fk_lp_kasa` int(11) NOT NULL DEFAULT 0,
  `fk_lp_palet` int(11) NOT NULL DEFAULT 0,
  `fk_pm` int(11) NOT NULL DEFAULT 0,
  `fk_msm` int(11) NOT NULL DEFAULT 0,
  `fk_total` int(11) NOT NULL DEFAULT 0,
  `u0307` tinyint(1) NOT NULL DEFAULT 0,
  `unicode_flags` varchar(200) NOT NULL DEFAULT '',
  `risk_level` enum('DÜŞÜK','ORTA','YÜKSEK') NOT NULL DEFAULT 'DÜŞÜK',
  `status` enum('pending','approved','excluded') NOT NULL DEFAULT 'pending',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_target` (`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `normalize_migration_queue`
--

LOCK TABLES `normalize_migration_queue` WRITE;
/*!40000 ALTER TABLE `normalize_migration_queue` DISABLE KEYS */;
INSERT INTO `normalize_migration_queue` VALUES
(1,1,'kasa_cinsi','Plastik Kasa (Standart)','Plastik Kasa (standart)',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(2,2,'kasa_cinsi','Plastik Kasa (BÃ¼yÃ¼k)','Plastik Kasa (bã¼yã¼k)',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(3,7,'palet_tipi','Tek KullanÄ±mlÄ±k Palet','Tek Kullanä±mlä±k Palet',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(4,9,'kosebent','Plastik KÃ¶ÅŸebent (4 lÃ¼)','Plastik Kã¶åÿebent (4 Lã¼)',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(5,10,'serit','PP Åžerit','Pp Åžerit',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(6,15,'taban_kagidi','Taban KaÄŸÄ±dÄ±','Taban Kaäÿä±dä±',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(7,18,'kose_karton','KÃ¶ÅŸe Karton','Kã¶åÿe Karton',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(8,19,'kraft_kagit','Kraft KaÄŸÄ±t','Kraft Kaäÿä±t',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14'),
(9,23,'kasa_cinsi','Plastik (K-65)','Plastik (k-65)',1,0,0,NULL,NULL,0,0,0,0,0,0,'','DÜŞÜK','approved','2026-05-28 14:27:14','2026-05-28 14:27:14');
/*!40000 ALTER TABLE `normalize_migration_queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pallet_materials`
--

DROP TABLE IF EXISTS `pallet_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pallet_materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loading_pallet_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
  `total_dara_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_pm_material` (`material_id`),
  KEY `idx_pm_pallet` (`loading_pallet_id`),
  CONSTRAINT `fk_pm_material` FOREIGN KEY (`material_id`) REFERENCES `material_definitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_pallet` FOREIGN KEY (`loading_pallet_id`) REFERENCES `loading_pallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pallet_materials`
--

LOCK TABLES `pallet_materials` WRITE;
/*!40000 ALTER TABLE `pallet_materials` DISABLE KEYS */;
/*!40000 ALTER TABLE `pallet_materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_counts`
--

DROP TABLE IF EXISTS `stock_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_counts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `count_date` date NOT NULL,
  `firma` varchar(150) NOT NULL DEFAULT '',
  `urun` varchar(150) NOT NULL DEFAULT '',
  `depo` varchar(150) NOT NULL DEFAULT '',
  `parti_no` varchar(80) NOT NULL DEFAULT '',
  `system_kg` decimal(12,3) NOT NULL DEFAULT 0.000,
  `counted_kg` decimal(12,3) NOT NULL DEFAULT 0.000,
  `diff_kg` decimal(12,3) NOT NULL DEFAULT 0.000,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sc_date` (`count_date`),
  KEY `idx_sc_firma` (`firma`(80)),
  KEY `idx_sc_urun` (`urun`(80)),
  KEY `idx_sc_depo` (`depo`(80)),
  KEY `idx_sc_parti` (`parti_no`(40))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_counts`
--

LOCK TABLES `stock_counts` WRITE;
/*!40000 ALTER TABLE `stock_counts` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_counts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-28 14:40:33
