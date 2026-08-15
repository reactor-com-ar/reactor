-- Esquema de referencia de reactor (ver CLAUDE.md).
--
-- Base convertida a InnoDB / utf8mb4_unicode_ci y con 99 claves foraneas
-- declaradas en produccion (98 en desarrollo). Este volcado sale de DEV.
--
-- La FK que falta en dev es `programas`.`dominio`: esa tabla existe en
-- `reactor` pero no en `reactor_dev` (drift previo a estas migraciones).
--
--
-- CRITERIO DE LA REGLA ON DELETE
--
--   RESTRICT  Relaciones estructurales y de catalogo. Es el default.
--   SET NULL  Punteros al "ultimo usado" (`usuarios`.`perfil`, `dispositivos`.
--             `adopcion`, `dominios`.`contrato`) y bitacoras / atribucion
--             historica, donde el historial no debe gobernar el ciclo de vida
--             del padre (`registros`.*, `senales`.*, `notificaciones`,
--             `sucesos`, `usuarios`.`registrante`, `casos`.`autor`).
--   CASCADE   Filas de detalle propiedad de su padre (`comprobantesrenglones`,
--             `dispositivosparametros`, `gadgets`.`dashboard`, `carritos`).
--
--   Los ciclos (usuarios<->perfiles, dispositivos<->adopciones,
--   dominios<->contratos) se resolvieron haciendo ceder siempre al puntero y
--   manteniendo RESTRICT del lado estructural. `menus`.`padre` y
--   `usuarios`.`registrante` son auto-referenciales: eso NO es un ciclo.
--
--   OJO: una FK auto-referencial garantiza que el padre existe, pero NO que la
--   jerarquia sea un arbol. InnoDB acepta ciclos (A->B->C->A) y filas que son
--   su propio padre. Para `menus` eso hay que validarlo en la aplicacion.
--
--
-- LA FILA CENTINELA id = 0  -- EXCEPCION DELIBERADA
--
--   `usuarios`, `perfiles` y `canales` tienen una fila artificial con id = 0
--   y nombre "(sin asignar)", creada por 20260814_3100. Existe porque el
--   sistema legacy -- fuera de este repositorio -- escribe 0 como centinela en
--   `sesiones`.`usuario`, `sesiones`.`perfil` y `senales`.`canal`. Sin esa
--   fila las FK habrian rechazado el 99,85% de los INSERT de sesion y el 68%
--   de la ingesta MQTT.
--
--   En el RESTO del esquema la convencion es la contraria: el 0 se convirtio a
--   NULL. Si algun dia el legacy escribe NULL, migrar estas tres a la
--   convencion general y eliminar las filas centinela.
--
--   El usuario centinela NO puede iniciar sesion: `usuario` = NULL (el login
--   filtra por esa columna y NULL nunca matchea), `habilitado` = 'N' y
--   `contrasena` vacia.
--
--   PENDIENTE: los listados que hacen SELECT sin filtrar muestran esas filas.
--   Agregar `WHERE id <> 0` en `panel/api/usuarios.php` y equivalentes.
--
--
-- Toda tabla y columna nueva debe crearse en InnoDB / utf8mb4_unicode_ci.
-- El resto del esquema sigue relacionandose por soft FK (sin constraint).
--
-- Sin datos: solo estructura, vistas, rutinas y triggers.

-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: reactor_dev
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accesos___`
--

DROP TABLE IF EXISTS `accesos___`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accesos___` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `activaciones`
--

DROP TABLE IF EXISTS `activaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `generada` datetime DEFAULT NULL,
  `objeto` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identidad` int DEFAULT NULL,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completada` datetime DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `actividades`
--

DROP TABLE IF EXISTS `actividades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `actividades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `prospecto` int DEFAULT NULL,
  `origen` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medio` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detalle` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `estado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrada` datetime DEFAULT NULL,
  `programada` datetime DEFAULT NULL,
  `completada` datetime DEFAULT NULL,
  `cartera` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `adjuntos`
--

DROP TABLE IF EXISTS `adjuntos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adjuntos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `objeto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identidad` int DEFAULT NULL,
  `categoria` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` int DEFAULT NULL,
  `extension` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archivo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cargado` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `adjuntoscategorias`
--

DROP TABLE IF EXISTS `adjuntoscategorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adjuntoscategorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `adjuntosobjetos__`
--

DROP TABLE IF EXISTS `adjuntosobjetos__`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adjuntosobjetos__` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `atras` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `adjuntostipos__`
--

DROP TABLE IF EXISTS `adjuntostipos__`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adjuntostipos__` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `adopciones`
--

DROP TABLE IF EXISTS `adopciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adopciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dispositivo` int DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `adoptado` datetime DEFAULT NULL,
  `adoptador` int DEFAULT NULL,
  `liberado` datetime DEFAULT NULL,
  `liberador` int DEFAULT NULL,
  `vigente` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_adopciones_dispositivo` (`dispositivo`),
  KEY `fk_adopciones_dominio` (`dominio`),
  KEY `fk_adopciones_adoptador` (`adoptador`),
  KEY `fk_adopciones_liberador` (`liberador`),
  CONSTRAINT `fk_adopciones_adoptador` FOREIGN KEY (`adoptador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_adopciones_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_adopciones_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_adopciones_liberador` FOREIGN KEY (`liberador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agentes`
--

DROP TABLE IF EXISTS `agentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agentes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsable` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `web` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad` int DEFAULT NULL,
  `provincia` int DEFAULT NULL,
  `pais` int DEFAULT NULL,
  `ubicacion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrado` datetime DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agentesfunciones`
--

DROP TABLE IF EXISTS `agentesfunciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agentesfunciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `aplicaciones`
--

DROP TABLE IF EXISTS `aplicaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aplicaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `apikey` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `apisecret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usos` int DEFAULT NULL,
  `habilitada` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_aplicaciones_dominio` (`dominio`),
  CONSTRAINT `fk_aplicaciones_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `articulos`
--

DROP TABLE IF EXISTS `articulos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `articulos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoria` int DEFAULT NULL,
  `marca` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` mediumtext COLLATE utf8mb4_unicode_ci,
  `metadatos` mediumtext COLLATE utf8mb4_unicode_ci,
  `sku` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ean` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actual` int DEFAULT NULL,
  `minimo` int DEFAULT NULL,
  `recomendado` int DEFAULT NULL,
  `iva` decimal(10,2) DEFAULT NULL,
  `moneda` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `importacion` decimal(10,2) DEFAULT NULL,
  `compra` decimal(10,2) DEFAULT NULL,
  `margen` decimal(10,2) DEFAULT NULL,
  `venta` decimal(10,2) DEFAULT NULL,
  `web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `visibilidad` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_articulos_categoria` (`categoria`),
  CONSTRAINT `fk_articulos_categoria` FOREIGN KEY (`categoria`) REFERENCES `articuloscategorias` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `articulos___`
--

DROP TABLE IF EXISTS `articulos___`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `articulos___` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoria` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marca` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` mediumtext COLLATE utf8mb4_unicode_ci,
  `metadatos` mediumtext COLLATE utf8mb4_unicode_ci,
  `sku` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ean` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actual` int DEFAULT NULL,
  `minimo` int DEFAULT NULL,
  `recomendado` int DEFAULT NULL,
  `iva` decimal(10,2) DEFAULT NULL,
  `moneda` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `importacion` decimal(10,2) DEFAULT NULL,
  `compra` decimal(10,2) DEFAULT NULL,
  `margen` decimal(10,2) DEFAULT NULL,
  `venta` decimal(10,2) DEFAULT NULL,
  `margen2` decimal(10,2) DEFAULT NULL,
  `venta2` decimal(10,2) DEFAULT NULL,
  `margen3` decimal(10,2) DEFAULT NULL,
  `venta3` decimal(10,2) DEFAULT NULL,
  `proveedor` int DEFAULT NULL,
  `proveedor2` int DEFAULT NULL,
  `proveedor3` int DEFAULT NULL,
  `web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `componente` tinyint(1) DEFAULT NULL,
  `compuesto` tinyint(1) DEFAULT NULL,
  `visibilidad` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `articuloscategorias`
--

DROP TABLE IF EXISTS `articuloscategorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `articuloscategorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `jerarquia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `articuloscomponentes`
--

DROP TABLE IF EXISTS `articuloscomponentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `articuloscomponentes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `producto` int DEFAULT NULL,
  `componente` int DEFAULT NULL,
  `requiere` int DEFAULT NULL,
  `disponible` int DEFAULT NULL,
  `capacidad` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `articuloscomponentesvista`
--

DROP TABLE IF EXISTS `articuloscomponentesvista`;
/*!50001 DROP VIEW IF EXISTS `articuloscomponentesvista`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `articuloscomponentesvista` AS SELECT 
 1 AS `componenteNombre`,
 1 AS `id`,
 1 AS `producto`,
 1 AS `componente`,
 1 AS `requiere`,
 1 AS `disponible`,
 1 AS `capacidad`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `articulosimagenes`
--

DROP TABLE IF EXISTS `articulosimagenes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `articulosimagenes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `articulo` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `autenticaciones`
--

DROP TABLE IF EXISTS `autenticaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autenticaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario` int DEFAULT NULL,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emision` datetime DEFAULT NULL,
  `uso` datetime DEFAULT NULL,
  `vencimiento` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `avisos`
--

DROP TABLE IF EXISTS `avisos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `avisos` (
  `id` int NOT NULL,
  `cuenta` int DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `destino` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destinatario` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asunto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mensajee` mediumtext COLLATE utf8mb4_unicode_ci,
  `envio` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bancos`
--

DROP TABLE IF EXISTS `bancos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bancos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `botones`
--

DROP TABLE IF EXISTS `botones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `botones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `panel` int DEFAULT NULL,
  `control` int DEFAULT NULL,
  `dispositivo` int DEFAULT NULL,
  `canal` int DEFAULT NULL,
  `accion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `texto` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icono` int DEFAULT NULL,
  `ancho` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` smallint DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_botones_dominio` (`dominio`),
  KEY `fk_botones_panel` (`panel`),
  KEY `fk_botones_control` (`control`),
  KEY `fk_botones_dispositivo` (`dispositivo`),
  KEY `fk_botones_canal` (`canal`),
  KEY `fk_botones_icono` (`icono`),
  CONSTRAINT `fk_botones_canal` FOREIGN KEY (`canal`) REFERENCES `canales` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_botones_control` FOREIGN KEY (`control`) REFERENCES `controles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_botones_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_botones_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_botones_icono` FOREIGN KEY (`icono`) REFERENCES `iconos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_botones_panel` FOREIGN KEY (`panel`) REFERENCES `paneles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `campanas`
--

DROP TABLE IF EXISTS `campanas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `campanas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitada` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `canales`
--

DROP TABLE IF EXISTS `canales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `canales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dispositivo` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal` smallint DEFAULT NULL,
  `modulo` int DEFAULT NULL,
  `estado` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `usos` int DEFAULT NULL,
  `usoDiario` int DEFAULT NULL,
  `usoMensual` int DEFAULT NULL,
  `usoAcumulado` int DEFAULT NULL,
  `usado` datetime DEFAULT NULL,
  `registrosGuardar` smallint DEFAULT NULL,
  `registrosLimite` int DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  `configuracion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `opciones` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `reacciones` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_canales_dispositivo` (`dispositivo`),
  KEY `fk_canales_modulo` (`modulo`),
  CONSTRAINT `fk_canales_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_canales_modulo` FOREIGN KEY (`modulo`) REFERENCES `modulos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `canales___`
--

DROP TABLE IF EXISTS `canales___`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `canales___` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identificador` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dispositivo` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal` smallint DEFAULT NULL,
  `modulo` int DEFAULT NULL,
  `estado` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `usos` int DEFAULT NULL,
  `usoDiario` int DEFAULT NULL,
  `usoMensual` int DEFAULT NULL,
  `usoAcumulado` int DEFAULT NULL,
  `usado` datetime DEFAULT NULL,
  `registrosGuardar` smallint DEFAULT NULL,
  `registrosLimite` int DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  `configuracion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `opciones` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `reacciones` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `carritos`
--

DROP TABLE IF EXISTS `carritos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carritos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario` int DEFAULT NULL,
  `items` int DEFAULT NULL,
  `total` decimal(11,2) DEFAULT NULL,
  `modificado` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_carritos_usuario` (`usuario`),
  CONSTRAINT `fk_carritos_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `carritositems`
--

DROP TABLE IF EXISTS `carritositems`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carritositems` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario` int DEFAULT NULL,
  `cantidad` smallint DEFAULT NULL,
  `articulo` int DEFAULT NULL,
  `detalle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unitario` decimal(11,2) DEFAULT NULL,
  `monto` decimal(11,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_carritositems_usuario` (`usuario`),
  KEY `fk_carritositems_articulo` (`articulo`),
  CONSTRAINT `fk_carritositems_articulo` FOREIGN KEY (`articulo`) REFERENCES `articulos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_carritositems_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `carteras`
--

DROP TABLE IF EXISTS `carteras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carteras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ejecutivo` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_carteras_ejecutivo` (`ejecutivo`),
  CONSTRAINT `fk_carteras_ejecutivo` FOREIGN KEY (`ejecutivo`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `casos`
--

DROP TABLE IF EXISTS `casos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `casos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `apertura` datetime DEFAULT NULL,
  `autor` int DEFAULT NULL,
  `area` int DEFAULT NULL,
  `objeto` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identidad` int DEFAULT NULL,
  `asunto` mediumtext COLLATE utf8mb4_unicode_ci,
  `asignado` int DEFAULT NULL,
  `prioridad` tinyint(1) DEFAULT NULL,
  `actualizaciones` mediumtext COLLATE utf8mb4_unicode_ci,
  `vencimiento` datetime DEFAULT NULL,
  `cierre` datetime DEFAULT NULL,
  `estado` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_casos_autor` (`autor`),
  CONSTRAINT `fk_casos_autor` FOREIGN KEY (`autor`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chips`
--

DROP TABLE IF EXISTS `chips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `titular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsable` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serie` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compania` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `datos` int DEFAULT NULL,
  `mensajes` int DEFAULT NULL,
  `articulo` int DEFAULT NULL,
  `registrado` date DEFAULT NULL,
  `recargado` date DEFAULT NULL,
  `vencimiento` date DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  `comentario` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_chips_dominio` (`dominio`),
  KEY `fk_chips_articulo` (`articulo`),
  CONSTRAINT `fk_chips_articulo` FOREIGN KEY (`articulo`) REFERENCES `articulos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_chips_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `circuitos`
--

DROP TABLE IF EXISTS `circuitos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `circuitos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imagen` int DEFAULT NULL,
  `alta` datetime DEFAULT NULL,
  `modificacion` datetime DEFAULT NULL,
  `estado` int DEFAULT NULL,
  `comentarios` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clientes`
--

DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuit` varchar(13) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `talonario` int DEFAULT NULL,
  `medio` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_clientes_talonario` (`talonario`),
  CONSTRAINT `fk_clientes_talonario` FOREIGN KEY (`talonario`) REFERENCES `talonarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clientes___`
--

DROP TABLE IF EXISTS `clientes___`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes___` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuit` varchar(13) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `talonario` int DEFAULT NULL,
  `medio` int DEFAULT NULL,
  `cartera` int DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `colores`
--

DROP TABLE IF EXISTS `colores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `colores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` smallint DEFAULT NULL,
  `visible` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `combos`
--

DROP TABLE IF EXISTS `combos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `combos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `combo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `texto` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `comprobantes`
--

DROP TABLE IF EXISTS `comprobantes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comprobantes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `talonario` int DEFAULT NULL,
  `serie` int DEFAULT NULL,
  `caenro` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caevto` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caeres` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emision` date DEFAULT NULL,
  `vencimiento` date DEFAULT NULL,
  `contrato` int DEFAULT NULL,
  `cliente` int DEFAULT NULL,
  `razon` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicion` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(11,2) DEFAULT NULL,
  `iva` decimal(11,2) DEFAULT NULL,
  `total` decimal(11,2) DEFAULT NULL,
  `cotizacion` decimal(11,2) DEFAULT NULL,
  `observaciones` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comentarios` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medio` int DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_comprobantes_talonario` (`talonario`),
  KEY `fk_comprobantes_contrato` (`contrato`),
  KEY `fk_comprobantes_cliente` (`cliente`),
  KEY `fk_comprobantes_medio` (`medio`),
  CONSTRAINT `fk_comprobantes_cliente` FOREIGN KEY (`cliente`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_comprobantes_contrato` FOREIGN KEY (`contrato`) REFERENCES `contratos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_comprobantes_medio` FOREIGN KEY (`medio`) REFERENCES `medios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_comprobantes_talonario` FOREIGN KEY (`talonario`) REFERENCES `talonarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `comprobantesrenglones`
--

DROP TABLE IF EXISTS `comprobantesrenglones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comprobantesrenglones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `comprobante` int DEFAULT NULL,
  `orden` smallint DEFAULT NULL,
  `cantidad` decimal(11,2) DEFAULT NULL,
  `articulo` int DEFAULT NULL,
  `detalle` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iva` decimal(11,2) DEFAULT NULL,
  `unitario` decimal(11,2) DEFAULT NULL,
  `monto` decimal(11,2) DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_renglones_comprobante` (`comprobante`),
  KEY `fk_renglones_articulo` (`articulo`),
  CONSTRAINT `fk_renglones_articulo` FOREIGN KEY (`articulo`) REFERENCES `articulos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_renglones_comprobante` FOREIGN KEY (`comprobante`) REFERENCES `comprobantes` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `comprobantesvista`
--

DROP TABLE IF EXISTS `comprobantesvista`;
/*!50001 DROP VIEW IF EXISTS `comprobantesvista`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `comprobantesvista` AS SELECT 
 1 AS `talonarioEmpresa`,
 1 AS `talonarioTipo`,
 1 AS `talonarioSubtipo`,
 1 AS `talonarioPunto`,
 1 AS `talonarioFiscal`,
 1 AS `id`,
 1 AS `uuid`,
 1 AS `talonario`,
 1 AS `serie`,
 1 AS `caenro`,
 1 AS `caevto`,
 1 AS `caeres`,
 1 AS `emision`,
 1 AS `vencimiento`,
 1 AS `contrato`,
 1 AS `cliente`,
 1 AS `razon`,
 1 AS `condicion`,
 1 AS `cuit`,
 1 AS `domicilio`,
 1 AS `correo`,
 1 AS `celular`,
 1 AS `subtotal`,
 1 AS `iva`,
 1 AS `total`,
 1 AS `cotizacion`,
 1 AS `observaciones`,
 1 AS `comentarios`,
 1 AS `medio`,
 1 AS `estado`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `contratos`
--

DROP TABLE IF EXISTS `contratos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contratos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cliente` int DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `tipo` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan` int DEFAULT NULL,
  `promo` int DEFAULT NULL,
  `desde` date DEFAULT NULL,
  `hasta` date DEFAULT NULL,
  `registro` datetime DEFAULT NULL,
  `firma` datetime DEFAULT NULL,
  `alta` date DEFAULT NULL,
  `baja` date DEFAULT NULL,
  `facturado` date DEFAULT NULL,
  `facturar` date DEFAULT NULL,
  `tolerancia` date DEFAULT NULL,
  `remitir` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remitido` datetime DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_contratos_cliente` (`cliente`),
  KEY `fk_contratos_dominio` (`dominio`),
  KEY `fk_contratos_plan` (`plan`),
  KEY `fk_contratos_promo` (`promo`),
  CONSTRAINT `fk_contratos_cliente` FOREIGN KEY (`cliente`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contratos_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contratos_plan` FOREIGN KEY (`plan`) REFERENCES `planes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contratos_promo` FOREIGN KEY (`promo`) REFERENCES `articulos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `controles`
--

DROP TABLE IF EXISTS `controles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `controles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `panel` int DEFAULT NULL,
  `dispositivo` int DEFAULT NULL,
  `nombre` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` int DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parametros` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_controles_dominio` (`dominio`),
  KEY `fk_controles_panel` (`panel`),
  KEY `fk_controles_dispositivo` (`dispositivo`),
  CONSTRAINT `fk_controles_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_controles_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_controles_panel` FOREIGN KEY (`panel`) REFERENCES `paneles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `correos`
--

DROP TABLE IF EXISTS `correos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `correos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `remitente` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remite` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destinatario` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destino` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asunto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuerpo` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `plantilla` smallint DEFAULT NULL,
  `encolado` datetime DEFAULT NULL,
  `enviado` datetime DEFAULT NULL,
  `envio` smallint DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dashboards`
--

DROP TABLE IF EXISTS `dashboards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dashboards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_dashboards_dominio` (`dominio`),
  CONSTRAINT `fk_dashboards_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dashboardscomparticiones`
--

DROP TABLE IF EXISTS `dashboardscomparticiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dashboardscomparticiones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dashboard` int DEFAULT NULL,
  `desde` datetime DEFAULT NULL,
  `hasta` datetime DEFAULT NULL,
  `habilitada` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dispositivos`
--

DROP TABLE IF EXISTS `dispositivos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispositivos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agente` int DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `transceptor` int DEFAULT NULL,
  `modelo` int DEFAULT NULL,
  `producto` int DEFAULT NULL,
  `firmware` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mac` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `senal` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identidad` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `llave` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chip` int DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  `senalesLimite` int DEFAULT NULL,
  `fabricacion` datetime DEFAULT NULL,
  `adoptado` smallint DEFAULT NULL,
  `adopcion` int DEFAULT NULL,
  `instalacion` datetime DEFAULT NULL,
  `inicio` datetime DEFAULT NULL,
  `conexion` datetime DEFAULT NULL,
  `latido` datetime DEFAULT NULL,
  `inicios` int DEFAULT NULL,
  `conexiones` int DEFAULT NULL,
  `latidos` int DEFAULT NULL,
  `enlace` smallint DEFAULT NULL,
  `monitoreo` smallint DEFAULT NULL,
  `monitoreoIntervalo` int DEFAULT NULL,
  `monitoreoCorreos` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monitoreoUltimo` datetime DEFAULT NULL,
  `monitoreoSiguiente` datetime DEFAULT NULL,
  `coordenadas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indicadores` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_dispositivos_agente` (`agente`),
  KEY `fk_dispositivos_dominio` (`dominio`),
  KEY `fk_dispositivos_transceptor` (`transceptor`),
  KEY `fk_dispositivos_modelo` (`modelo`),
  KEY `fk_dispositivos_producto` (`producto`),
  KEY `fk_dispositivos_chip` (`chip`),
  KEY `fk_dispositivos_adopcion` (`adopcion`),
  CONSTRAINT `fk_dispositivos_adopcion` FOREIGN KEY (`adopcion`) REFERENCES `adopciones` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_dispositivos_agente` FOREIGN KEY (`agente`) REFERENCES `agentes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_dispositivos_chip` FOREIGN KEY (`chip`) REFERENCES `chips` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_dispositivos_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_dispositivos_modelo` FOREIGN KEY (`modelo`) REFERENCES `modelos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_dispositivos_producto` FOREIGN KEY (`producto`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_dispositivos_transceptor` FOREIGN KEY (`transceptor`) REFERENCES `transceptores` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dispositivos___`
--

DROP TABLE IF EXISTS `dispositivos___`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispositivos___` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identificador` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agente` int DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `transceptor` int DEFAULT NULL,
  `modelo` int DEFAULT NULL,
  `producto` int DEFAULT NULL,
  `firmware` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mac` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `senal` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identidad` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `llave` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chip` int DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  `senalesLimite` int DEFAULT NULL,
  `fabricacion` datetime DEFAULT NULL,
  `adoptado` smallint DEFAULT NULL,
  `adopcion` int DEFAULT NULL,
  `instalacion` datetime DEFAULT NULL,
  `inicio` datetime DEFAULT NULL,
  `conexion` datetime DEFAULT NULL,
  `latido` datetime DEFAULT NULL,
  `inicios` int DEFAULT NULL,
  `conexiones` int DEFAULT NULL,
  `latidos` int DEFAULT NULL,
  `enlace` smallint DEFAULT NULL,
  `monitoreo` smallint DEFAULT NULL,
  `monitoreoIntervalo` int DEFAULT NULL,
  `monitoreoCorreos` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monitoreoUltimo` datetime DEFAULT NULL,
  `monitoreoSiguiente` datetime DEFAULT NULL,
  `coordenadas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indicadores` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dispositivosparametros`
--

DROP TABLE IF EXISTS `dispositivosparametros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispositivosparametros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dispositivo` int DEFAULT NULL,
  `variable` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enviado` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_dispositivosparametros_dispositivo` (`dispositivo`),
  CONSTRAINT `fk_dispositivosparametros_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dispositivosvariables`
--

DROP TABLE IF EXISTS `dispositivosvariables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispositivosvariables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `distribuidores`
--

DROP TABLE IF EXISTS `distribuidores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `distribuidores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuit` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `empleados` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rubro` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `web` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsable` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cargo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrado` datetime DEFAULT NULL,
  `logotipo` smallint DEFAULT NULL,
  `visibilidad` smallint DEFAULT NULL,
  `valoracion` smallint DEFAULT NULL,
  `admision` smallint DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documentos`
--

DROP TABLE IF EXISTS `documentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `identificador` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` int DEFAULT NULL,
  `categoria` int DEFAULT NULL,
  `volanta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bajada` mediumtext COLLATE utf8mb4_unicode_ci,
  `cuerpo` mediumtext COLLATE utf8mb4_unicode_ci,
  `metadatos` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creado` datetime DEFAULT NULL,
  `modificado` datetime DEFAULT NULL,
  `visible` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documentoscategorias`
--

DROP TABLE IF EXISTS `documentoscategorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentoscategorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` int DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dominios`
--

DROP TABLE IF EXISTS `dominios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dominios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agente` int DEFAULT NULL,
  `cliente` int DEFAULT NULL,
  `contrato` int DEFAULT NULL,
  `autoadministrado` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuarios` int DEFAULT NULL,
  `dispositivos` int DEFAULT NULL,
  `chips` int DEFAULT NULL,
  `usos` int DEFAULT NULL,
  `paneles` int DEFAULT NULL,
  `situacion` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_dominios_agente` (`agente`),
  KEY `fk_dominios_cliente` (`cliente`),
  KEY `fk_dominios_contrato` (`contrato`),
  CONSTRAINT `fk_dominios_agente` FOREIGN KEY (`agente`) REFERENCES `agentes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_dominios_cliente` FOREIGN KEY (`cliente`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_dominios_contrato` FOREIGN KEY (`contrato`) REFERENCES `contratos` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dominiosguardias`
--

DROP TABLE IF EXISTS `dominiosguardias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dominiosguardias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `correo` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_dominiosguardias_dominio` (`dominio`),
  CONSTRAINT `fk_dominiosguardias_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dominiosmedios`
--

DROP TABLE IF EXISTS `dominiosmedios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dominiosmedios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `principal` smallint DEFAULT NULL,
  `alta` datetime DEFAULT NULL,
  `uso` datetime DEFAULT NULL,
  `baja` datetime DEFAULT NULL,
  `validado` smallint DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_dominiosmedios_dominio` (`dominio`),
  CONSTRAINT `fk_dominiosmedios_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dominiosmediosbancos`
--

DROP TABLE IF EXISTS `dominiosmediosbancos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dominiosmediosbancos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `medio` int DEFAULT NULL,
  `banco` int DEFAULT NULL,
  `titular` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cbu` varchar(23) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dominiosmediostarjetas`
--

DROP TABLE IF EXISTS `dominiosmediostarjetas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dominiosmediostarjetas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `medio` int DEFAULT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `titular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vencimiento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `empaques`
--

DROP TABLE IF EXISTS `empaques`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `empaques` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modelo` int DEFAULT NULL,
  `version` smallint DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_empaques_modelo` (`modelo`),
  CONSTRAINT `fk_empaques_modelo` FOREIGN KEY (`modelo`) REFERENCES `modelos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `empleados___`
--

DROP TABLE IF EXISTS `empleados___`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `empleados___` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `empresas`
--

DROP TABLE IF EXISTS `empresas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `empresas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iibb` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inicio` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `enlaces___`
--

DROP TABLE IF EXISTS `enlaces___`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enlaces___` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `objeto` int DEFAULT NULL,
  `parametros` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interactivo` smallint DEFAULT NULL,
  `clave` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `desde` datetime DEFAULT NULL,
  `hasta` datetime DEFAULT NULL,
  `generador` int DEFAULT NULL,
  `generado` datetime DEFAULT NULL,
  `utilizado` datetime DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `entradas`
--

DROP TABLE IF EXISTS `entradas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `entradas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `categoria` int DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `autor` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `volanta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `titulo` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bajada` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuerpo` mediumtext COLLATE utf8mb4_unicode_ci,
  `etiquetas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `miniatura` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imagen` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visibilidad` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadatos` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_entradas_categoria` (`categoria`),
  CONSTRAINT `fk_entradas_categoria` FOREIGN KEY (`categoria`) REFERENCES `entradascategorias` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `entradascategorias`
--

DROP TABLE IF EXISTS `entradascategorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `entradascategorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `padre` int DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `envases`
--

DROP TABLE IF EXISTS `envases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `envases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modelo` int DEFAULT NULL,
  `version` smallint DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_envases_modelo` (`modelo`),
  CONSTRAINT `fk_envases_modelo` FOREIGN KEY (`modelo`) REFERENCES `modelos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `errores`
--

DROP TABLE IF EXISTS `errores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `errores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mensaje` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contexto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `etiquetas`
--

DROP TABLE IF EXISTS `etiquetas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `etiquetas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dispositivo` int DEFAULT NULL,
  `impresion` smallint DEFAULT NULL,
  `impresa` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_etiquetas_dispositivo` (`dispositivo`),
  CONSTRAINT `fk_etiquetas_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exhibidores`
--

DROP TABLE IF EXISTS `exhibidores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibidores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modelo` int DEFAULT NULL,
  `version` smallint DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  `metadatos` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_exhibidores_modelo` (`modelo`),
  CONSTRAINT `fk_exhibidores_modelo` FOREIGN KEY (`modelo`) REFERENCES `modelos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fallas`
--

DROP TABLE IF EXISTS `fallas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fallas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT NULL,
  `origen` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detalle` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visible` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `figuras`
--

DROP TABLE IF EXISTS `figuras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `figuras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `gadgets`
--

DROP TABLE IF EXISTS `gadgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gadgets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `dashboard` int DEFAULT NULL,
  `tipo` int DEFAULT NULL,
  `orden` smallint DEFAULT NULL,
  `visible` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parametros` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_gadgets_dominio` (`dominio`),
  KEY `fk_gadgets_dashboard` (`dashboard`),
  CONSTRAINT `fk_gadgets_dashboard` FOREIGN KEY (`dashboard`) REFERENCES `dashboards` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_gadgets_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `gadgetstipos`
--

DROP TABLE IF EXISTS `gadgetstipos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gadgetstipos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `icono` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ancho` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alto` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `generaciones`
--

DROP TABLE IF EXISTS `generaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `generaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `periodo` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `iconos`
--

DROP TABLE IF EXISTS `iconos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `iconos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visible` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `imagenes`
--

DROP TABLE IF EXISTS `imagenes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `imagenes` (
  `id` int NOT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archivo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `informe001`
--

DROP TABLE IF EXISTS `informe001`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `informe001` (
  `id` int NOT NULL,
  `generado` datetime DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `usuarios` int DEFAULT NULL,
  `equipos` int DEFAULT NULL,
  `usoDiario` int DEFAULT NULL,
  `UsoMensual` int DEFAULT NULL,
  `UsoAcumulado` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inscriptos`
--

DROP TABLE IF EXISTS `inscriptos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inscriptos` (
  `id` int NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `instaladores`
--

DROP TABLE IF EXISTS `instaladores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `instaladores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actividad` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad_` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia_` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais_` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrado` datetime DEFAULT NULL,
  `aprobacion` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visibilidad` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invitaciones`
--

DROP TABLE IF EXISTS `invitaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invitaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `emisor` int DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emitida` datetime DEFAULT NULL,
  `abierta` datetime DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_invitaciones_dominio` (`dominio`),
  KEY `fk_invitaciones_emisor` (`emisor`),
  CONSTRAINT `fk_invitaciones_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitaciones_emisor` FOREIGN KEY (`emisor`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invitados`
--

DROP TABLE IF EXISTS `invitados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invitados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cuenta` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lexicos`
--

DROP TABLE IF EXISTS `lexicos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lexicos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `termino` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `significado` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `llamadas`
--

DROP TABLE IF EXISTS `llamadas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `llamadas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo` int DEFAULT NULL,
  `mensaje` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `llaves`
--

DROP TABLE IF EXISTS `llaves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `llaves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identificador` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `objeto` int DEFAULT NULL,
  `parametros` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interactiva` smallint DEFAULT NULL,
  `acceso` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clave` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `desde` datetime DEFAULT NULL,
  `hasta` datetime DEFAULT NULL,
  `generador` int DEFAULT NULL,
  `generada` datetime DEFAULT NULL,
  `utilizada` datetime DEFAULT NULL,
  `usos` int DEFAULT NULL,
  `habilitada` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_llaves_dominio` (`dominio`),
  KEY `fk_llaves_generador` (`generador`),
  CONSTRAINT `fk_llaves_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_llaves_generador` FOREIGN KEY (`generador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `localidades`
--

DROP TABLE IF EXISTS `localidades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `localidades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pais` int DEFAULT NULL,
  `provincia` int DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoria` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `propagacion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `manuales`
--

DROP TABLE IF EXISTS `manuales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `manuales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modelo` smallint DEFAULT NULL,
  `version` smallint DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `medios`
--

DROP TABLE IF EXISTS `medios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `medios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `grupo` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciclico` tinyint(1) DEFAULT NULL,
  `cuenta` int DEFAULT NULL,
  `estado` tinyint(1) DEFAULT NULL,
  `comentarios` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mensajes`
--

DROP TABLE IF EXISTS `mensajes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mensajes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `canal` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `destinatario` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destino` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `texto` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `encolado` datetime DEFAULT NULL,
  `programado` datetime DEFAULT NULL,
  `enviado` datetime DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_mensajes_usuario` (`usuario`),
  CONSTRAINT `fk_mensajes_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `menus`
--

DROP TABLE IF EXISTS `menus`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menus` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nivel` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `padre` int DEFAULT NULL,
  `orden` tinyint DEFAULT NULL,
  `icono` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destino` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ventana` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inicio` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_menus_padre` (`padre`),
  CONSTRAINT `fk_menus_padre` FOREIGN KEY (`padre`) REFERENCES `menus` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `menusvista`
--

DROP TABLE IF EXISTS `menusvista`;
/*!50001 DROP VIEW IF EXISTS `menusvista`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `menusvista` AS SELECT 
 1 AS `padreNombre`,
 1 AS `padreNivel`,
 1 AS `id`,
 1 AS `nivel`,
 1 AS `padre`,
 1 AS `orden`,
 1 AS `icono`,
 1 AS `nombre`,
 1 AS `destino`,
 1 AS `ventana`,
 1 AS `inicio`,
 1 AS `estado`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `microservicios`
--

DROP TABLE IF EXISTS `microservicios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `microservicios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metodo` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pruebas` int DEFAULT NULL,
  `probado` datetime DEFAULT NULL,
  `respuesta` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migraciones`
--

DROP TABLE IF EXISTS `migraciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migraciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aplicada` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `misivas`
--

DROP TABLE IF EXISTS `misivas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `misivas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asunto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mensaje` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modelos`
--

DROP TABLE IF EXISTS `modelos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modelos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canales` int DEFAULT NULL,
  `canal0` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal3` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal4` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal5` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal6` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal7` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `canal8` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `especificaciones` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modulos`
--

DROP TABLE IF EXISTS `modulos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modulos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `componente` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `imagen` int DEFAULT NULL,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opera` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pines` smallint DEFAULT NULL,
  `comentarios` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notificaciones`
--

DROP TABLE IF EXISTS `notificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `icono` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mensaje` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destino` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `leida` smallint DEFAULT NULL,
  `visible` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_notificaciones_dominio` (`dominio`),
  CONSTRAINT `fk_notificaciones_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pagos`
--

DROP TABLE IF EXISTS `pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pagos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `contrato` int DEFAULT NULL,
  `comprobante` int DEFAULT NULL,
  `monto` decimal(11,2) DEFAULT NULL,
  `medio` int DEFAULT NULL,
  `operacion` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pagos_dominio` (`dominio`),
  KEY `fk_pagos_contrato` (`contrato`),
  KEY `fk_pagos_comprobante` (`comprobante`),
  KEY `fk_pagos_medio` (`medio`),
  CONSTRAINT `fk_pagos_comprobante` FOREIGN KEY (`comprobante`) REFERENCES `comprobantes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pagos_contrato` FOREIGN KEY (`contrato`) REFERENCES `contratos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pagos_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pagos_medio` FOREIGN KEY (`medio`) REFERENCES `medios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paises`
--

DROP TABLE IF EXISTS `paises`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paises` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `propagacion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paneles`
--

DROP TABLE IF EXISTS `paneles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paneles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_paneles_dominio` (`dominio`),
  CONSTRAINT `fk_paneles_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `parametros`
--

DROP TABLE IF EXISTS `parametros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parametros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `variable` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comentario` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `perfiles`
--

DROP TABLE IF EXISTS `perfiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `perfiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `roles` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rol` int DEFAULT NULL,
  `paneles` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'paneles habilitados',
  `panel` int DEFAULT NULL COMMENT 'id del ultimo panel',
  `permisos` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_perfiles_usuario` (`usuario`),
  KEY `fk_perfiles_dominio` (`dominio`),
  KEY `fk_perfiles_rol` (`rol`),
  KEY `fk_perfiles_panel` (`panel`),
  CONSTRAINT `fk_perfiles_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_perfiles_panel` FOREIGN KEY (`panel`) REFERENCES `paneles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_perfiles_rol` FOREIGN KEY (`rol`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_perfiles_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `perfilesvista`
--

DROP TABLE IF EXISTS `perfilesvista`;
/*!50001 DROP VIEW IF EXISTS `perfilesvista`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `perfilesvista` AS SELECT 
 1 AS `dominioNombre`,
 1 AS `usuarioNombre`,
 1 AS `usuarioCorreo`,
 1 AS `usuarioCelular`,
 1 AS `rolNombre`,
 1 AS `id`,
 1 AS `uuid`,
 1 AS `nombre`,
 1 AS `usuario`,
 1 AS `dominio`,
 1 AS `tipo`,
 1 AS `roles`,
 1 AS `rol`,
 1 AS `paneles`,
 1 AS `panel`,
 1 AS `permisos`,
 1 AS `habilitado`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `permisos`
--

DROP TABLE IF EXISTS `permisos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permisos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sistema` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `planes`
--

DROP TABLE IF EXISTS `planes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  `articulo` int DEFAULT NULL,
  `usuarios` int DEFAULT NULL,
  `dispositivos` int DEFAULT NULL,
  `usos` int DEFAULT NULL,
  `orden` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_planes_articulo` (`articulo`),
  CONSTRAINT `fk_planes_articulo` FOREIGN KEY (`articulo`) REFERENCES `articulos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `productos`
--

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generacion` int DEFAULT NULL,
  `propiedades` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `presentado` datetime DEFAULT NULL,
  `discontinuado` datetime DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ean` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documento` int DEFAULT NULL,
  `imagen` int DEFAULT NULL,
  `envase` int DEFAULT NULL,
  `empaque` int DEFAULT NULL,
  `manual` int DEFAULT NULL,
  `articulo` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prospectos`
--

DROP TABLE IF EXISTS `prospectos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prospectos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `empresa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rubro` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrado` datetime DEFAULT NULL,
  `contactado` datetime DEFAULT NULL,
  `actividades` int DEFAULT NULL,
  `interes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cartera` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_prospectos_cartera` (`cartera`),
  CONSTRAINT `fk_prospectos_cartera` FOREIGN KEY (`cartera`) REFERENCES `carteras` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `provincias`
--

DROP TABLE IF EXISTS `provincias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `provincias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pais` int DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoria` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `propagacion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `publicidades`
--

DROP TABLE IF EXISTS `publicidades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `publicidades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` date DEFAULT NULL,
  `campana` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canal` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `referencias`
--

DROP TABLE IF EXISTS `referencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `referencias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadatos` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `solicitud` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `respuesta` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `visible` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `registros`
--

DROP TABLE IF EXISTS `registros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT NULL,
  `sentido` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `dispositivo` int DEFAULT NULL,
  `canal` int DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_registros_usuario` (`usuario`),
  KEY `fk_registros_dominio` (`dominio`),
  KEY `fk_registros_dispositivo` (`dispositivo`),
  KEY `fk_registros_canal` (`canal`),
  CONSTRAINT `fk_registros_canal` FOREIGN KEY (`canal`) REFERENCES `canales` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_registros_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_registros_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_registros_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sistema` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nivel` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  `menus` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accesos` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permisos` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `senales`
--

DROP TABLE IF EXISTS `senales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `senales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `serie` int DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `sentido` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transceptor` int DEFAULT NULL,
  `dispositivo` int DEFAULT NULL,
  `canal` int DEFAULT NULL,
  `topic` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mensaje` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_senales_transceptor` (`transceptor`),
  KEY `fk_senales_dispositivo` (`dispositivo`),
  KEY `fk_senales_canal` (`canal`),
  CONSTRAINT `fk_senales_canal` FOREIGN KEY (`canal`) REFERENCES `canales` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_senales_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_senales_transceptor` FOREIGN KEY (`transceptor`) REFERENCES `transceptores` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `senales_por_minuto`
--

DROP TABLE IF EXISTS `senales_por_minuto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `senales_por_minuto` (
  `minuto` datetime NOT NULL,
  `cantidad` int unsigned NOT NULL DEFAULT '0',
  `generado_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`minuto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sesiones`
--

DROP TABLE IF EXISTS `sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sesiones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `terminal` int DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `perfil` int DEFAULT NULL,
  `mantener` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iniciada` datetime DEFAULT NULL,
  `usada` datetime DEFAULT NULL,
  `expira` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_sesiones_terminal` (`terminal`),
  KEY `fk_sesiones_usuario` (`usuario`),
  KEY `fk_sesiones_perfil` (`perfil`),
  CONSTRAINT `fk_sesiones_perfil` FOREIGN KEY (`perfil`) REFERENCES `perfiles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sesiones_terminal` FOREIGN KEY (`terminal`) REFERENCES `terminales` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_sesiones_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sucesos`
--

DROP TABLE IF EXISTS `sucesos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sucesos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `usuario` int DEFAULT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detalle` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_sucesos_dominio` (`dominio`),
  KEY `fk_sucesos_usuario` (`usuario`),
  CONSTRAINT `fk_sucesos_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_sucesos_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sucesos_log`
--

DROP TABLE IF EXISTS `sucesos_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sucesos_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT NULL,
  `origen` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `detalle` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `talonarios`
--

DROP TABLE IF EXISTS `talonarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `talonarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `empresa` int DEFAULT NULL,
  `tipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtipo` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `punto` int DEFAULT NULL,
  `serie` int DEFAULT NULL,
  `fiscal` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `web` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fondo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `terminos` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_talonarios_empresa` (`empresa`),
  CONSTRAINT `fk_talonarios_empresa` FOREIGN KEY (`empresa`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tareas`
--

DROP TABLE IF EXISTS `tareas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tareas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `script` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cron_expr` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `overlap` enum('skip','allow') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'skip',
  `timeout_seg` int unsigned NOT NULL DEFAULT '300',
  `retencion_dias` int unsigned NOT NULL DEFAULT '7',
  `ultimo_run` datetime DEFAULT NULL,
  `ultimo_estado` enum('ok','error','timeout','killed','corriendo') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ultimo_error` text COLLATE utf8mb4_unicode_ci,
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tareas_nombre` (`nombre`),
  KEY `idx_tareas_activo_ultimo_run` (`activo`,`ultimo_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tareas_ejecuciones`
--

DROP TABLE IF EXISTS `tareas_ejecuciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tareas_ejecuciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tarea_id` int unsigned NOT NULL,
  `pid` int unsigned DEFAULT NULL,
  `inicio` datetime NOT NULL,
  `fin` datetime DEFAULT NULL,
  `estado` enum('corriendo','ok','error','timeout','killed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'corriendo',
  `exit_code` int DEFAULT NULL,
  `mensaje` text COLLATE utf8mb4_unicode_ci,
  `log_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disparo` enum('scheduler','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduler',
  PRIMARY KEY (`id`),
  KEY `idx_tareas_ej_tarea_id` (`tarea_id`,`id`),
  KEY `idx_tareas_ej_estado` (`estado`),
  KEY `idx_tareas_ej_inicio` (`inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `temporizadores`
--

DROP TABLE IF EXISTS `temporizadores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `temporizadores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `identificador` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `programa` int DEFAULT NULL,
  `cron` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitar` datetime DEFAULT NULL,
  `deshabilitar` datetime DEFAULT NULL,
  `primera` datetime DEFAULT NULL,
  `ultima` datetime DEFAULT NULL,
  `ejecuciones` int DEFAULT NULL,
  `habilitado` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `terminales`
--

DROP TABLE IF EXISTS `terminales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `terminales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sistema` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `navegador` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrada` datetime DEFAULT NULL,
  `conectada` datetime DEFAULT NULL,
  `autorizada` smallint DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transceptores`
--

DROP TABLE IF EXISTS `transceptores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transceptores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `puerto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contrasena` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entrada` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usos`
--

DROP TABLE IF EXISTS `usos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` date DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `dispositivo` int DEFAULT NULL,
  `entrantes` int DEFAULT NULL,
  `salientes` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_usos_dominio` (`dominio`),
  KEY `fk_usos_dispositivo` (`dispositivo`),
  CONSTRAINT `fk_usos_dispositivo` FOREIGN KEY (`dispositivo`) REFERENCES `dispositivos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_usos_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autenticacion` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contrasena` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clave` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrante` int DEFAULT NULL,
  `registrado` datetime DEFAULT NULL,
  `ingresado` datetime DEFAULT NULL,
  `perfiles` int DEFAULT NULL,
  `perfil` int DEFAULT NULL,
  `roles` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dominios` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `paneles` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `panel` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_usuarios_perfil` (`perfil`),
  KEY `fk_usuarios_dominio` (`dominio`),
  KEY `fk_usuarios_panel` (`panel`),
  KEY `fk_usuarios_registrante` (`registrante`),
  CONSTRAINT `fk_usuarios_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_usuarios_panel` FOREIGN KEY (`panel`) REFERENCES `paneles` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_usuarios_perfil` FOREIGN KEY (`perfil`) REFERENCES `perfiles` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_usuarios_registrante` FOREIGN KEY (`registrante`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usuariosgrupos`
--

DROP TABLE IF EXISTS `usuariosgrupos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuariosgrupos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `nombre` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `habilitado` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_usuariosgrupos_dominio` (`dominio`),
  CONSTRAINT `fk_usuariosgrupos_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `utilizaciones`
--

DROP TABLE IF EXISTS `utilizaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `utilizaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT NULL,
  `dominio` int DEFAULT NULL,
  `plan` int DEFAULT NULL,
  `usuarios` int DEFAULT NULL,
  `dispositivos` int DEFAULT NULL,
  `usos` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_utilizaciones_dominio` (`dominio`),
  KEY `fk_utilizaciones_plan` (`plan`),
  CONSTRAINT `fk_utilizaciones_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_utilizaciones_plan` FOREIGN KEY (`plan`) REFERENCES `planes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `videos`
--

DROP TABLE IF EXISTS `videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `videos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `categoria` int DEFAULT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visibilidad` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `widget005`
--

DROP TABLE IF EXISTS `widget005`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `widget005` (
  `id` int NOT NULL AUTO_INCREMENT,
  `periodo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `altas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bajas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resultado` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `widgets`
--

DROP TABLE IF EXISTS `widgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `widgets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dominio` int DEFAULT NULL,
  `panel` int DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` int DEFAULT NULL,
  `objeto` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `identidad` int DEFAULT NULL,
  `orden` smallint DEFAULT NULL,
  `visible` smallint DEFAULT NULL,
  `parametros` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actualizado` datetime DEFAULT NULL,
  `usado` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fk_widgets_dominio` (`dominio`),
  KEY `fk_widgets_panel` (`panel`),
  CONSTRAINT `fk_widgets_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_widgets_panel` FOREIGN KEY (`panel`) REFERENCES `paneles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `widgetstipos`
--

DROP TABLE IF EXISTS `widgetstipos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `widgetstipos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `objeto` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `modulos` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'reactor_dev'
--

--
-- Dumping routines for database 'reactor_dev'
--

--
-- Final view structure for view `articuloscomponentesvista`
--

/*!50001 DROP VIEW IF EXISTS `articuloscomponentesvista`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY INVOKER */
/*!50001 VIEW `articuloscomponentesvista` AS select `articulos`.`nombre` AS `componenteNombre`,`articuloscomponentes`.`id` AS `id`,`articuloscomponentes`.`producto` AS `producto`,`articuloscomponentes`.`componente` AS `componente`,`articuloscomponentes`.`requiere` AS `requiere`,`articuloscomponentes`.`disponible` AS `disponible`,`articuloscomponentes`.`capacidad` AS `capacidad` from (`articulos` join `articuloscomponentes` on((`articuloscomponentes`.`componente` = `articulos`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `comprobantesvista`
--

/*!50001 DROP VIEW IF EXISTS `comprobantesvista`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY INVOKER */
/*!50001 VIEW `comprobantesvista` AS select `talonarios`.`empresa` AS `talonarioEmpresa`,`talonarios`.`tipo` AS `talonarioTipo`,`talonarios`.`subtipo` AS `talonarioSubtipo`,`talonarios`.`punto` AS `talonarioPunto`,`talonarios`.`fiscal` AS `talonarioFiscal`,`comprobantes`.`id` AS `id`,`comprobantes`.`uuid` AS `uuid`,`comprobantes`.`talonario` AS `talonario`,`comprobantes`.`serie` AS `serie`,`comprobantes`.`caenro` AS `caenro`,`comprobantes`.`caevto` AS `caevto`,`comprobantes`.`caeres` AS `caeres`,`comprobantes`.`emision` AS `emision`,`comprobantes`.`vencimiento` AS `vencimiento`,`comprobantes`.`contrato` AS `contrato`,`comprobantes`.`cliente` AS `cliente`,`comprobantes`.`razon` AS `razon`,`comprobantes`.`condicion` AS `condicion`,`comprobantes`.`cuit` AS `cuit`,`comprobantes`.`domicilio` AS `domicilio`,`comprobantes`.`correo` AS `correo`,`comprobantes`.`celular` AS `celular`,`comprobantes`.`subtotal` AS `subtotal`,`comprobantes`.`iva` AS `iva`,`comprobantes`.`total` AS `total`,`comprobantes`.`cotizacion` AS `cotizacion`,`comprobantes`.`observaciones` AS `observaciones`,`comprobantes`.`comentarios` AS `comentarios`,`comprobantes`.`medio` AS `medio`,`comprobantes`.`estado` AS `estado` from (`comprobantes` join `talonarios` on((`comprobantes`.`talonario` = `talonarios`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `menusvista`
--

/*!50001 DROP VIEW IF EXISTS `menusvista`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY INVOKER */
/*!50001 VIEW `menusvista` AS select `menus2`.`nombre` AS `padreNombre`,`menus2`.`nivel` AS `padreNivel`,`menus`.`id` AS `id`,`menus`.`nivel` AS `nivel`,`menus`.`padre` AS `padre`,`menus`.`orden` AS `orden`,`menus`.`icono` AS `icono`,`menus`.`nombre` AS `nombre`,`menus`.`destino` AS `destino`,`menus`.`ventana` AS `ventana`,`menus`.`inicio` AS `inicio`,`menus`.`estado` AS `estado` from (`menus` join `menus` `menus2` on((`menus`.`padre` = `menus2`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `perfilesvista`
--

/*!50001 DROP VIEW IF EXISTS `perfilesvista`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY INVOKER */
/*!50001 VIEW `perfilesvista` AS select `dominios`.`nombre` AS `dominioNombre`,`usuarios`.`nombre` AS `usuarioNombre`,`usuarios`.`correo` AS `usuarioCorreo`,`usuarios`.`celular` AS `usuarioCelular`,`roles`.`nombre` AS `rolNombre`,`perfiles`.`id` AS `id`,`perfiles`.`uuid` AS `uuid`,`perfiles`.`nombre` AS `nombre`,`perfiles`.`usuario` AS `usuario`,`perfiles`.`dominio` AS `dominio`,`perfiles`.`tipo` AS `tipo`,`perfiles`.`roles` AS `roles`,`perfiles`.`rol` AS `rol`,`perfiles`.`paneles` AS `paneles`,`perfiles`.`panel` AS `panel`,`perfiles`.`permisos` AS `permisos`,`perfiles`.`habilitado` AS `habilitado` from (((`perfiles` left join `usuarios` on((`perfiles`.`usuario` = `usuarios`.`id`))) left join `dominios` on((`perfiles`.`dominio` = `dominios`.`id`))) left join `roles` on((`perfiles`.`rol` = `roles`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
