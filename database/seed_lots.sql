-- ============================================================
-- Seed: alle kavels 1–205 op basis van Excel eindafrekening 2025
-- Bebouwde kavels = kavels waarvoor meterstanden zijn ingevoerd
-- Onbebouwde kavels = kavels zonder verbruik
-- ============================================================

INSERT INTO lots (lot_number, lot_type) VALUES
-- Bebouwd (actieve kavels met verbruik uit Excel)
(1,'bebouwd'),(2,'bebouwd'),(3,'bebouwd'),(4,'bebouwd'),(5,'bebouwd'),
(6,'bebouwd'),(7,'bebouwd'),(8,'bebouwd'),(9,'bebouwd'),(10,'bebouwd'),
(11,'bebouwd'),(12,'bebouwd'),(13,'bebouwd'),(14,'bebouwd'),(15,'bebouwd'),
(16,'bebouwd'),(17,'bebouwd'),(18,'bebouwd'),(19,'bebouwd'),(20,'bebouwd'),
(21,'bebouwd'),(22,'bebouwd'),(23,'bebouwd'),(24,'bebouwd'),(25,'bebouwd'),
(26,'bebouwd'),(27,'bebouwd'),(28,'bebouwd'),(29,'bebouwd'),(30,'bebouwd'),
(31,'bebouwd'),(32,'bebouwd'),(33,'bebouwd'),(34,'bebouwd'),(35,'bebouwd'),
(36,'bebouwd'),(37,'bebouwd'),(38,'bebouwd'),(39,'bebouwd'),
-- Kavel 40 ontbreekt in Excel (mogelijk niet bestaand)
(41,'bebouwd'),(42,'bebouwd'),(43,'bebouwd'),(44,'bebouwd'),(45,'bebouwd'),
(46,'bebouwd'),(47,'bebouwd'),(48,'bebouwd'),(49,'bebouwd'),(50,'bebouwd'),
(51,'bebouwd'),(52,'bebouwd'),(53,'bebouwd'),(54,'bebouwd'),(55,'bebouwd'),
(56,'bebouwd'),(57,'bebouwd'),(58,'bebouwd'),(59,'bebouwd'),(60,'bebouwd'),
(61,'bebouwd'),(62,'bebouwd'),(63,'bebouwd'),(64,'bebouwd'),(65,'bebouwd'),
(66,'bebouwd'),(67,'bebouwd'),(68,'bebouwd'),(69,'bebouwd'),(70,'bebouwd'),
(71,'bebouwd'),(72,'onbebouwd'),(73,'onbebouwd'),(74,'bebouwd'),(75,'bebouwd'),
(76,'onbebouwd'),(77,'onbebouwd'),(78,'bebouwd'),(79,'bebouwd'),(80,'bebouwd'),
(81,'bebouwd'),(82,'bebouwd'),(83,'bebouwd'),(84,'bebouwd'),(85,'bebouwd'),
(86,'bebouwd'),(87,'bebouwd'),(88,'bebouwd'),(89,'bebouwd'),
(90,'onbebouwd'),(91,'onbebouwd'),
(92,'bebouwd'),(93,'bebouwd'),(94,'bebouwd'),(95,'bebouwd'),(96,'bebouwd'),
(97,'bebouwd'),(98,'bebouwd'),(99,'bebouwd'),(100,'bebouwd'),
(101,'bebouwd'),(102,'bebouwd'),(103,'bebouwd'),(104,'bebouwd'),(105,'bebouwd'),
(106,'bebouwd'),(107,'bebouwd'),(108,'bebouwd'),
(109,'onbebouwd'),(110,'onbebouwd'),(111,'bebouwd'),(112,'onbebouwd'),(113,'onbebouwd'),
(114,'onbebouwd'),(115,'onbebouwd'),
(116,'bebouwd'),(117,'bebouwd'),(118,'bebouwd'),(119,'bebouwd'),(120,'bebouwd'),
(121,'bebouwd'),(122,'bebouwd'),(123,'onbebouwd'),(124,'bebouwd'),(125,'bebouwd'),
(126,'bebouwd'),(127,'onbebouwd'),(128,'onbebouwd'),(129,'bebouwd'),(130,'bebouwd'),
(131,'bebouwd'),(132,'onbebouwd'),(133,'onbebouwd'),(134,'onbebouwd'),(135,'onbebouwd'),
(136,'bebouwd'),(137,'onbebouwd'),(138,'onbebouwd'),(139,'onbebouwd'),(140,'onbebouwd'),
(141,'onbebouwd'),(142,'bebouwd'),(143,'onbebouwd'),(144,'onbebouwd'),(145,'onbebouwd'),
(146,'bebouwd'),(147,'onbebouwd'),(148,'onbebouwd'),(149,'onbebouwd'),(150,'bebouwd'),
(151,'bebouwd'),(152,'bebouwd'),(153,'bebouwd'),(154,'onbebouwd'),(155,'bebouwd'),
(156,'bebouwd'),(157,'bebouwd'),(158,'bebouwd'),(159,'bebouwd'),(160,'bebouwd'),
(161,'bebouwd'),(162,'bebouwd'),(163,'bebouwd'),(164,'bebouwd'),(165,'bebouwd'),
(166,'onbebouwd'),(167,'bebouwd'),(168,'onbebouwd'),(169,'onbebouwd'),(170,'onbebouwd'),
(171,'onbebouwd'),(172,'onbebouwd'),(173,'onbebouwd'),(174,'bebouwd'),(175,'bebouwd'),
(176,'bebouwd'),(177,'onbebouwd'),(178,'onbebouwd'),(179,'onbebouwd'),(180,'onbebouwd'),
(181,'bebouwd'),(182,'onbebouwd'),(183,'onbebouwd'),(184,'onbebouwd'),(185,'onbebouwd'),
(186,'onbebouwd'),(187,'bebouwd'),(188,'onbebouwd'),(189,'bebouwd'),(190,'onbebouwd'),
(191,'onbebouwd'),(192,'onbebouwd'),(193,'onbebouwd'),(194,'bebouwd'),(195,'bebouwd'),
(196,'bebouwd'),(197,'bebouwd'),(198,'bebouwd'),(199,'bebouwd'),(200,'onbebouwd'),
(201,'onbebouwd'),(202,'bebouwd'),(203,'bebouwd'),(204,'bebouwd'),(205,'bebouwd');
