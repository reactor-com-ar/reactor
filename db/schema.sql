/*
 Navicat Premium Data Transfer

 Source Server         : localhost - reactor
 Source Server Type    : MySQL
 Source Server Version : 80046
 Source Host           : localhost:3308
 Source Schema         : reactor_dev

 Target Server Type    : MySQL
 Target Server Version : 80046
 File Encoding         : 65001

 Date: 19/05/2026 18:23:24
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for activaciones
-- ----------------------------
DROP TABLE IF EXISTS `activaciones`;
CREATE TABLE `activaciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `generada` datetime(0) NULL DEFAULT NULL,
  `objeto` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `identidad` int(0) NULL DEFAULT NULL,
  `token` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `completada` datetime(0) NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 30 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for actividades
-- ----------------------------
DROP TABLE IF EXISTS `actividades`;
CREATE TABLE `actividades`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `prospecto` int(0) NULL DEFAULT NULL,
  `origen` varchar(3) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `medio` varchar(3) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `detalle` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `estado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `registrada` datetime(0) NULL DEFAULT NULL,
  `programada` datetime(0) NULL DEFAULT NULL,
  `completada` datetime(0) NULL DEFAULT NULL,
  `cartera` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 307 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for adjuntos
-- ----------------------------
DROP TABLE IF EXISTS `adjuntos`;
CREATE TABLE `adjuntos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `objeto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `identidad` int(0) NULL DEFAULT NULL,
  `categoria` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `tipo` int(0) NULL DEFAULT NULL,
  `extension` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `archivo` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `cargado` datetime(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 199 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for adjuntoscategorias
-- ----------------------------
DROP TABLE IF EXISTS `adjuntoscategorias`;
CREATE TABLE `adjuntoscategorias`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 16 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for adopciones
-- ----------------------------
DROP TABLE IF EXISTS `adopciones`;
CREATE TABLE `adopciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `adoptado` datetime(0) NULL DEFAULT NULL,
  `adoptador` int(0) NULL DEFAULT NULL,
  `liberado` datetime(0) NULL DEFAULT NULL,
  `liberador` int(0) NULL DEFAULT NULL,
  `vigente` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 55782 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for agentes
-- ----------------------------
DROP TABLE IF EXISTS `agentes`;
CREATE TABLE `agentes`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `responsable` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `celular` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `web` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `postal` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `localidad` int(0) NULL DEFAULT NULL,
  `provincia` int(0) NULL DEFAULT NULL,
  `pais` int(0) NULL DEFAULT NULL,
  `ubicacion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `registrado` datetime(0) NULL DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 228 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for agentesfunciones
-- ----------------------------
DROP TABLE IF EXISTS `agentesfunciones`;
CREATE TABLE `agentesfunciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for aplicaciones
-- ----------------------------
DROP TABLE IF EXISTS `aplicaciones`;
CREATE TABLE `aplicaciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `apikey` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `apisecret` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `usos` int(0) NULL DEFAULT NULL,
  `habilitada` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 19 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for articulos
-- ----------------------------
DROP TABLE IF EXISTS `articulos`;
CREATE TABLE `articulos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `categoria` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `marca` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `descripcion` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `metadatos` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `sku` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `ean` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `actual` int(0) NULL DEFAULT NULL,
  `minimo` int(0) NULL DEFAULT NULL,
  `recomendado` int(0) NULL DEFAULT NULL,
  `iva` decimal(10, 2) NULL DEFAULT NULL,
  `moneda` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `importacion` decimal(10, 2) NULL DEFAULT NULL,
  `compra` decimal(10, 2) NULL DEFAULT NULL,
  `margen` decimal(10, 2) NULL DEFAULT NULL,
  `venta` decimal(10, 2) NULL DEFAULT NULL,
  `web` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `visibilidad` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` tinyint(1) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 280 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for articuloscategorias
-- ----------------------------
DROP TABLE IF EXISTS `articuloscategorias`;
CREATE TABLE `articuloscategorias`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `jerarquia` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 8 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for articuloscomponentes
-- ----------------------------
DROP TABLE IF EXISTS `articuloscomponentes`;
CREATE TABLE `articuloscomponentes`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `producto` int(0) NULL DEFAULT NULL,
  `componente` int(0) NULL DEFAULT NULL,
  `requiere` int(0) NULL DEFAULT NULL,
  `disponible` int(0) NULL DEFAULT NULL,
  `capacidad` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 433 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Fixed;

-- ----------------------------
-- Table structure for articulosimagenes
-- ----------------------------
DROP TABLE IF EXISTS `articulosimagenes`;
CREATE TABLE `articulosimagenes`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `articulo` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for autenticaciones
-- ----------------------------
DROP TABLE IF EXISTS `autenticaciones`;
CREATE TABLE `autenticaciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `usuario` int(0) NULL DEFAULT NULL,
  `token` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `emision` datetime(0) NULL DEFAULT NULL,
  `uso` datetime(0) NULL DEFAULT NULL,
  `vencimiento` datetime(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 186 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for avisos
-- ----------------------------
DROP TABLE IF EXISTS `avisos`;
CREATE TABLE `avisos`  (
  `id` int(0) NOT NULL,
  `cuenta` int(0) NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `destino` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `destinatario` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `asunto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `mensajee` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `envio` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 1 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for bancos
-- ----------------------------
DROP TABLE IF EXISTS `bancos`;
CREATE TABLE `bancos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(3) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 42 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for botones
-- ----------------------------
DROP TABLE IF EXISTS `botones`;
CREATE TABLE `botones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `panel` int(0) NULL DEFAULT NULL,
  `control` int(0) NULL DEFAULT NULL,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `canal` int(0) NULL DEFAULT NULL,
  `accion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `texto` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `icono` int(0) NULL DEFAULT NULL,
  `ancho` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `orden` smallint(0) NULL DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `request` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 241 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for campanas
-- ----------------------------
DROP TABLE IF EXISTS `campanas`;
CREATE TABLE `campanas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `habilitada` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for canales
-- ----------------------------
DROP TABLE IF EXISTS `canales`;
CREATE TABLE `canales`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal` smallint(0) NULL DEFAULT NULL,
  `modulo` int(0) NULL DEFAULT NULL,
  `estado` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `usos` int(0) NULL DEFAULT NULL,
  `usoDiario` int(0) NULL DEFAULT NULL,
  `usoMensual` int(0) NULL DEFAULT NULL,
  `usoAcumulado` int(0) NULL DEFAULT NULL,
  `usado` datetime(0) NULL DEFAULT NULL,
  `registrosGuardar` smallint(0) NULL DEFAULT NULL,
  `registrosLimite` int(0) NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  `configuracion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `opciones` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `reacciones` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 731 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for carritos
-- ----------------------------
DROP TABLE IF EXISTS `carritos`;
CREATE TABLE `carritos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `usuario` int(0) NULL DEFAULT NULL,
  `items` int(0) NULL DEFAULT NULL,
  `total` decimal(11, 2) NULL DEFAULT NULL,
  `modificado` datetime(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for carritositems
-- ----------------------------
DROP TABLE IF EXISTS `carritositems`;
CREATE TABLE `carritositems`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `usuario` int(0) NULL DEFAULT NULL,
  `cantidad` smallint(0) NULL DEFAULT NULL,
  `articulo` int(0) NULL DEFAULT NULL,
  `detalle` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `unitario` decimal(11, 2) NULL DEFAULT NULL,
  `monto` decimal(11, 2) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1333 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for carteras
-- ----------------------------
DROP TABLE IF EXISTS `carteras`;
CREATE TABLE `carteras`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `ejecutivo` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 18 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for casos
-- ----------------------------
DROP TABLE IF EXISTS `casos`;
CREATE TABLE `casos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `apertura` datetime(0) NULL DEFAULT NULL,
  `autor` int(0) NULL DEFAULT NULL,
  `area` int(0) NULL DEFAULT NULL,
  `objeto` varchar(3) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `identidad` int(0) NULL DEFAULT NULL,
  `asunto` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `asignado` int(0) NULL DEFAULT NULL,
  `prioridad` tinyint(1) NULL DEFAULT NULL,
  `actualizaciones` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `vencimiento` datetime(0) NULL DEFAULT NULL,
  `cierre` datetime(0) NULL DEFAULT NULL,
  `estado` tinyint(1) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 1551 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for chips
-- ----------------------------
DROP TABLE IF EXISTS `chips`;
CREATE TABLE `chips`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `titular` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `responsable` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `pais` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `telefono` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `serie` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `compania` varchar(2) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `plan` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `datos` int(0) NULL DEFAULT NULL,
  `mensajes` int(0) NULL DEFAULT NULL,
  `articulo` int(0) NULL DEFAULT NULL,
  `registrado` date NULL DEFAULT NULL,
  `recargado` date NULL DEFAULT NULL,
  `vencimiento` date NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  `comentario` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 300 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for circuitos
-- ----------------------------
DROP TABLE IF EXISTS `circuitos`;
CREATE TABLE `circuitos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `imagen` int(0) NULL DEFAULT NULL,
  `alta` datetime(0) NULL DEFAULT NULL,
  `modificacion` datetime(0) NULL DEFAULT NULL,
  `estado` int(0) NULL DEFAULT NULL,
  `comentarios` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for clientes
-- ----------------------------
DROP TABLE IF EXISTS `clientes`;
CREATE TABLE `clientes`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `localidad` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `provincia` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `pais` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `contacto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `celular` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `razon` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `condicion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `cuit` varchar(13) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `talonario` int(0) NULL DEFAULT NULL,
  `medio` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 170 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for colores
-- ----------------------------
DROP TABLE IF EXISTS `colores`;
CREATE TABLE `colores`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `codigo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `orden` smallint(0) NULL DEFAULT NULL,
  `visible` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 110 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for combos
-- ----------------------------
DROP TABLE IF EXISTS `combos`;
CREATE TABLE `combos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `combo` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `orden` int(0) NULL DEFAULT NULL,
  `texto` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `valor` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 10836 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for comprobantes
-- ----------------------------
DROP TABLE IF EXISTS `comprobantes`;
CREATE TABLE `comprobantes`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(32) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `talonario` int(0) NULL DEFAULT NULL,
  `serie` int(0) NULL DEFAULT NULL,
  `caenro` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `caevto` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `caeres` varchar(250) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `emision` date NULL DEFAULT NULL,
  `vencimiento` date NULL DEFAULT NULL,
  `contrato` int(0) NULL DEFAULT NULL,
  `cliente` int(0) NULL DEFAULT NULL,
  `razon` varchar(250) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `condicion` varchar(2) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `cuit` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `domicilio` varchar(250) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `correo` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `celular` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `subtotal` decimal(11, 2) NULL DEFAULT NULL,
  `iva` decimal(11, 2) NULL DEFAULT NULL,
  `total` decimal(11, 2) NULL DEFAULT NULL,
  `cotizacion` decimal(11, 2) NULL DEFAULT NULL,
  `observaciones` varchar(2000) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `comentarios` varchar(2000) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `medio` int(0) NULL DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7594 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for comprobantesrenglones
-- ----------------------------
DROP TABLE IF EXISTS `comprobantesrenglones`;
CREATE TABLE `comprobantesrenglones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `comprobante` int(0) NULL DEFAULT NULL,
  `orden` smallint(0) NULL DEFAULT NULL,
  `cantidad` decimal(11, 2) NULL DEFAULT NULL,
  `articulo` int(0) NULL DEFAULT NULL,
  `detalle` varchar(500) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `iva` decimal(11, 2) NULL DEFAULT NULL,
  `unitario` decimal(11, 2) NULL DEFAULT NULL,
  `monto` decimal(11, 2) NULL DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7480 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for contratos
-- ----------------------------
DROP TABLE IF EXISTS `contratos`;
CREATE TABLE `contratos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(8) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `cliente` int(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `tipo` varchar(3) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `plan` int(0) NULL DEFAULT NULL,
  `promo` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `desde` date NULL DEFAULT NULL,
  `hasta` date NULL DEFAULT NULL,
  `registro` datetime(0) NULL DEFAULT NULL,
  `firma` datetime(0) NULL DEFAULT NULL,
  `alta` date NULL DEFAULT NULL,
  `baja` date NULL DEFAULT NULL,
  `facturado` date NULL DEFAULT NULL,
  `facturar` date NULL DEFAULT NULL,
  `tolerancia` date NULL DEFAULT NULL,
  `remitir` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `remitido` datetime(0) NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 161 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for controles
-- ----------------------------
DROP TABLE IF EXISTS `controles`;
CREATE TABLE `controles`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `panel` int(0) NULL DEFAULT NULL,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `nombre` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `color` int(0) NULL DEFAULT NULL,
  `orden` int(0) NULL DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `parametros` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 377 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for correos
-- ----------------------------
DROP TABLE IF EXISTS `correos`;
CREATE TABLE `correos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `remitente` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `remite` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `destinatario` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `destino` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `asunto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `cuerpo` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `plantilla` smallint(0) NULL DEFAULT NULL,
  `encolado` datetime(0) NULL DEFAULT NULL,
  `enviado` datetime(0) NULL DEFAULT NULL,
  `envio` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 57 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dashboards
-- ----------------------------
DROP TABLE IF EXISTS `dashboards`;
CREATE TABLE `dashboards`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 32 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dashboardscomparticiones
-- ----------------------------
DROP TABLE IF EXISTS `dashboardscomparticiones`;
CREATE TABLE `dashboardscomparticiones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `dashboard` int(0) NULL DEFAULT NULL,
  `desde` datetime(0) NULL DEFAULT NULL,
  `hasta` datetime(0) NULL DEFAULT NULL,
  `habilitada` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 3 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dispositivos
-- ----------------------------
DROP TABLE IF EXISTS `dispositivos`;
CREATE TABLE `dispositivos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `agente` int(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `transceptor` int(0) NULL DEFAULT NULL,
  `modelo` int(0) NULL DEFAULT NULL,
  `producto` int(0) NULL DEFAULT NULL,
  `firmware` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `mac` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `ip` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `senal` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `serial` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `identidad` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `llave` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `chip` int(0) NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  `senalesLimite` int(0) NULL DEFAULT NULL,
  `fabricacion` datetime(0) NULL DEFAULT NULL,
  `adoptado` smallint(0) NULL DEFAULT NULL,
  `adopcion` int(0) NULL DEFAULT NULL,
  `instalacion` datetime(0) NULL DEFAULT NULL,
  `inicio` datetime(0) NULL DEFAULT NULL,
  `conexion` datetime(0) NULL DEFAULT NULL,
  `latido` datetime(0) NULL DEFAULT NULL,
  `inicios` int(0) NULL DEFAULT NULL,
  `conexiones` int(0) NULL DEFAULT NULL,
  `latidos` int(0) NULL DEFAULT NULL,
  `enlace` smallint(0) NULL DEFAULT NULL,
  `monitoreo` smallint(0) NULL DEFAULT NULL,
  `monitoreoIntervalo` int(0) NULL DEFAULT NULL,
  `monitoreoCorreos` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `monitoreoUltimo` datetime(0) NULL DEFAULT NULL,
  `monitoreoSiguiente` datetime(0) NULL DEFAULT NULL,
  `coordenadas` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `indicadores` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 454 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dispositivosparametros
-- ----------------------------
DROP TABLE IF EXISTS `dispositivosparametros`;
CREATE TABLE `dispositivosparametros`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `variable` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `valor` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `enviado` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 16107 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dispositivosvariables
-- ----------------------------
DROP TABLE IF EXISTS `dispositivosvariables`;
CREATE TABLE `dispositivosvariables`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 57 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for distribuidores
-- ----------------------------
DROP TABLE IF EXISTS `distribuidores`;
CREATE TABLE `distribuidores`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `razon` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `cuit` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `condicion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `empleados` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `rubro` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `localidad` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `provincia` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `pais` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `telefono` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `web` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `responsable` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `cargo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `celular` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `registrado` datetime(0) NULL DEFAULT NULL,
  `logotipo` smallint(0) NULL DEFAULT NULL,
  `visibilidad` smallint(0) NULL DEFAULT NULL,
  `valoracion` smallint(0) NULL DEFAULT NULL,
  `admision` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 125 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for documentos
-- ----------------------------
DROP TABLE IF EXISTS `documentos`;
CREATE TABLE `documentos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `identificador` varchar(8) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `tipo` int(0) NULL DEFAULT NULL,
  `categoria` int(0) NULL DEFAULT NULL,
  `volanta` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `titulo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `bajada` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `cuerpo` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `metadatos` varchar(5000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `creado` datetime(0) NULL DEFAULT NULL,
  `modificado` datetime(0) NULL DEFAULT NULL,
  `visible` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 83 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for documentoscategorias
-- ----------------------------
DROP TABLE IF EXISTS `documentoscategorias`;
CREATE TABLE `documentoscategorias`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `tipo` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 12 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dominios
-- ----------------------------
DROP TABLE IF EXISTS `dominios`;
CREATE TABLE `dominios`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `numero` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `agente` int(0) NULL DEFAULT NULL,
  `cliente` int(0) NULL DEFAULT NULL,
  `contrato` int(0) NULL DEFAULT NULL,
  `autoadministrado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `usuarios` int(0) NULL DEFAULT NULL,
  `dispositivos` int(0) NULL DEFAULT NULL,
  `chips` int(0) NULL DEFAULT NULL,
  `usos` int(0) NULL DEFAULT NULL,
  `paneles` int(0) NULL DEFAULT NULL,
  `situacion` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 275 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dominiosguardias
-- ----------------------------
DROP TABLE IF EXISTS `dominiosguardias`;
CREATE TABLE `dominiosguardias`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `correo` varchar(250) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 502 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dominiosmedios
-- ----------------------------
DROP TABLE IF EXISTS `dominiosmedios`;
CREATE TABLE `dominiosmedios`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `tipo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `principal` smallint(0) NULL DEFAULT NULL,
  `alta` datetime(0) NULL DEFAULT NULL,
  `uso` datetime(0) NULL DEFAULT NULL,
  `baja` datetime(0) NULL DEFAULT NULL,
  `validado` smallint(0) NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 22 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dominiosmediosbancos
-- ----------------------------
DROP TABLE IF EXISTS `dominiosmediosbancos`;
CREATE TABLE `dominiosmediosbancos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `medio` int(0) NULL DEFAULT NULL,
  `banco` int(0) NULL DEFAULT NULL,
  `titular` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `cbu` varchar(23) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `dni` varchar(8) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 1 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for dominiosmediostarjetas
-- ----------------------------
DROP TABLE IF EXISTS `dominiosmediostarjetas`;
CREATE TABLE `dominiosmediostarjetas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `medio` int(0) NULL DEFAULT NULL,
  `tipo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `titular` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `numero` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `vencimiento` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `codigo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 19 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for empaques
-- ----------------------------
DROP TABLE IF EXISTS `empaques`;
CREATE TABLE `empaques`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(250) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `modelo` smallint(0) NULL DEFAULT NULL,
  `version` smallint(0) NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for empresas
-- ----------------------------
DROP TABLE IF EXISTS `empresas`;
CREATE TABLE `empresas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `razon` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `condicion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `cuit` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `iibb` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `inicio` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for entradas
-- ----------------------------
DROP TABLE IF EXISTS `entradas`;
CREATE TABLE `entradas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(500) CHARACTER SET utf32 COLLATE utf32_general_ci NULL DEFAULT NULL,
  `fecha` date NULL DEFAULT NULL,
  `categoria` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `orden` int(0) NULL DEFAULT NULL,
  `autor` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `volanta` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `titulo` varchar(500) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `bajada` varchar(5000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `cuerpo` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
  `etiquetas` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `miniatura` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `imagen` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `visibilidad` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `metadatos` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 123 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for entradascategorias
-- ----------------------------
DROP TABLE IF EXISTS `entradascategorias`;
CREATE TABLE `entradascategorias`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `padre` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 121 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for envases
-- ----------------------------
DROP TABLE IF EXISTS `envases`;
CREATE TABLE `envases`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(250) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `modelo` smallint(0) NULL DEFAULT NULL,
  `version` smallint(0) NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for errores
-- ----------------------------
DROP TABLE IF EXISTS `errores`;
CREATE TABLE `errores`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `mensaje` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `contexto` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 116 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for etiquetas
-- ----------------------------
DROP TABLE IF EXISTS `etiquetas`;
CREATE TABLE `etiquetas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `equipo` int(0) NULL DEFAULT NULL,
  `impresion` smallint(0) NULL DEFAULT NULL,
  `impresa` datetime(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1036 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for exhibidores
-- ----------------------------
DROP TABLE IF EXISTS `exhibidores`;
CREATE TABLE `exhibidores`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `modelo` smallint(0) NULL DEFAULT NULL,
  `version` smallint(0) NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  `metadatos` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for fallas
-- ----------------------------
DROP TABLE IF EXISTS `fallas`;
CREATE TABLE `fallas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `origen` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `detalle` varchar(1000) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `visible` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 134685 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for figuras
-- ----------------------------
DROP TABLE IF EXISTS `figuras`;
CREATE TABLE `figuras`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `archivo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 26 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for gadgets
-- ----------------------------
DROP TABLE IF EXISTS `gadgets`;
CREATE TABLE `gadgets`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `dashboard` int(0) NULL DEFAULT NULL,
  `tipo` int(0) NULL DEFAULT NULL,
  `orden` smallint(0) NULL DEFAULT NULL,
  `visible` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `parametros` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 13 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for gadgetstipos
-- ----------------------------
DROP TABLE IF EXISTS `gadgetstipos`;
CREATE TABLE `gadgetstipos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `icono` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `ancho` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `alto` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 12 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for generaciones
-- ----------------------------
DROP TABLE IF EXISTS `generaciones`;
CREATE TABLE `generaciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `periodo` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for iconos
-- ----------------------------
DROP TABLE IF EXISTS `iconos`;
CREATE TABLE `iconos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `codigo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `visible` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 173 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for imagenes
-- ----------------------------
DROP TABLE IF EXISTS `imagenes`;
CREATE TABLE `imagenes`  (
  `id` int(0) NOT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `archivo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for informe001
-- ----------------------------
DROP TABLE IF EXISTS `informe001`;
CREATE TABLE `informe001`  (
  `id` int(0) NOT NULL,
  `generado` datetime(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `usuarios` int(0) NULL DEFAULT NULL,
  `equipos` int(0) NULL DEFAULT NULL,
  `usoDiario` int(0) NULL DEFAULT NULL,
  `UsoMensual` int(0) NULL DEFAULT NULL,
  `UsoAcumulado` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for inscriptos
-- ----------------------------
DROP TABLE IF EXISTS `inscriptos`;
CREATE TABLE `inscriptos`  (
  `id` int(0) NOT NULL,
  `nombre` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for instaladores
-- ----------------------------
DROP TABLE IF EXISTS `instaladores`;
CREATE TABLE `instaladores`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `actividad` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `celular` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `postal` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `localidad` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `localidad_` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `provincia` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `provincia_` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `pais` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `pais_` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `registrado` datetime(0) NULL DEFAULT NULL,
  `aprobacion` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `visibilidad` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 614 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for invitaciones
-- ----------------------------
DROP TABLE IF EXISTS `invitaciones`;
CREATE TABLE `invitaciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `emisor` int(0) NULL DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `celular` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `correo` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `emitida` datetime(0) NULL DEFAULT NULL,
  `abierta` datetime(0) NULL DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1034 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for invitados
-- ----------------------------
DROP TABLE IF EXISTS `invitados`;
CREATE TABLE `invitados`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `cuenta` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `telefono` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for lexicos
-- ----------------------------
DROP TABLE IF EXISTS `lexicos`;
CREATE TABLE `lexicos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `termino` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `significado` varchar(500) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 114 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for llamadas
-- ----------------------------
DROP TABLE IF EXISTS `llamadas`;
CREATE TABLE `llamadas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `url` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `codigo` int(0) NULL DEFAULT NULL,
  `mensaje` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 697 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for llaves
-- ----------------------------
DROP TABLE IF EXISTS `llaves`;
CREATE TABLE `llaves`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `identificador` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `tipo` varchar(3) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `objeto` int(0) NULL DEFAULT NULL,
  `parametros` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `interactiva` smallint(0) NULL DEFAULT NULL,
  `acceso` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `clave` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `desde` datetime(0) NULL DEFAULT NULL,
  `hasta` datetime(0) NULL DEFAULT NULL,
  `generador` int(0) NULL DEFAULT NULL,
  `generada` datetime(0) NULL DEFAULT NULL,
  `utilizada` datetime(0) NULL DEFAULT NULL,
  `usos` int(0) NULL DEFAULT NULL,
  `habilitada` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 569 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for localidades
-- ----------------------------
DROP TABLE IF EXISTS `localidades`;
CREATE TABLE `localidades`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `pais` int(0) NULL DEFAULT NULL,
  `provincia` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `categoria` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `propagacion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 94077 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for manuales
-- ----------------------------
DROP TABLE IF EXISTS `manuales`;
CREATE TABLE `manuales`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(250) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `modelo` smallint(0) NULL DEFAULT NULL,
  `version` smallint(0) NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for medios
-- ----------------------------
DROP TABLE IF EXISTS `medios`;
CREATE TABLE `medios`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `grupo` varchar(3) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `tipo` varchar(3) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `ciclico` tinyint(1) NULL DEFAULT NULL,
  `cuenta` int(0) NULL DEFAULT NULL,
  `estado` tinyint(1) NULL DEFAULT NULL,
  `comentarios` varchar(5000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 22 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for mensajes
-- ----------------------------
DROP TABLE IF EXISTS `mensajes`;
CREATE TABLE `mensajes`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `canal` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `destinatario` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `destino` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `texto` varchar(1024) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `encolado` datetime(0) NULL DEFAULT NULL,
  `programado` datetime(0) NULL DEFAULT NULL,
  `enviado` datetime(0) NULL DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 185 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for menus
-- ----------------------------
DROP TABLE IF EXISTS `menus`;
CREATE TABLE `menus`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nivel` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `padre` int(0) NULL DEFAULT NULL,
  `orden` tinyint(0) NULL DEFAULT NULL,
  `icono` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `destino` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `ventana` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `inicio` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `estado` tinyint(1) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 369 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for migraciones
-- Ledger del Migrador DB (cloud). Una fila por migracion aplicada
-- exitosamente. La unicidad de `nombre` la garantiza el endpoint apply
-- en aplicacion (no hay UNIQUE a nivel DDL para permitir re-runs manuales).
-- ----------------------------
DROP TABLE IF EXISTS `migraciones`;
CREATE TABLE `migraciones`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `aplicada` datetime(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for tareas_cron
-- Programador de tareas (herramienta cloud): catalogo de procesos
-- automaticos programados por cron. Nombre distinto de la tabla legacy
-- `tareas` (mas abajo) para no colisionar con las apps historicas.
-- Ver skill crear_programador_de_tareas §2.
-- ----------------------------
DROP TABLE IF EXISTS `tareas_cron`;
CREATE TABLE `tareas_cron` (
    `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre`             varchar(120) NOT NULL,
    `descripcion`        varchar(255) NULL DEFAULT NULL,
    `script`             varchar(255) NOT NULL,
    `cron_expr`          varchar(80)  NOT NULL,
    `activo`             tinyint(1)   NOT NULL DEFAULT 1,
    `overlap`            enum('skip','allow') NOT NULL DEFAULT 'skip',
    `timeout_seg`        int(10) UNSIGNED NOT NULL DEFAULT 300,
    `retencion_dias`     int(10) UNSIGNED NOT NULL DEFAULT 7,
    `ultimo_run`         datetime NULL DEFAULT NULL,
    `ultimo_estado`      enum('ok','error','timeout','killed','corriendo') NULL DEFAULT NULL,
    `ultimo_error`       text NULL,
    `fecha_creacion`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_modificacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `uq_tareas_cron_nombre` (`nombre`) USING BTREE,
    KEY `idx_tareas_cron_activo_ultimo_run` (`activo`, `ultimo_run`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for tareas_cron_ejecuciones
-- Historial de corridas del scheduler + disparos manuales.
-- ----------------------------
DROP TABLE IF EXISTS `tareas_cron_ejecuciones`;
CREATE TABLE `tareas_cron_ejecuciones` (
    `id`        int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tarea_id`  int(10) UNSIGNED NOT NULL,
    `pid`       int(10) UNSIGNED NULL DEFAULT NULL,
    `inicio`    datetime NOT NULL,
    `fin`       datetime NULL DEFAULT NULL,
    `estado`    enum('corriendo','ok','error','timeout','killed') NOT NULL DEFAULT 'corriendo',
    `exit_code` int NULL DEFAULT NULL,
    `mensaje`   text NULL,
    `log_path`  varchar(255) NULL DEFAULT NULL,
    `disparo`   enum('scheduler','manual') NOT NULL DEFAULT 'scheduler',
    PRIMARY KEY (`id`) USING BTREE,
    KEY `idx_tareas_cron_ej_tarea_id` (`tarea_id`, `id`) USING BTREE,
    KEY `idx_tareas_cron_ej_estado`   (`estado`) USING BTREE,
    KEY `idx_tareas_cron_ej_inicio`   (`inicio`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for microservicios
-- ----------------------------
DROP TABLE IF EXISTS `microservicios`;
CREATE TABLE `microservicios`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `descripcion` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `version` varchar(5) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `metodo` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `pruebas` int(0) NULL DEFAULT NULL,
  `probado` datetime(0) NULL DEFAULT NULL,
  `respuesta` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for misivas
-- ----------------------------
DROP TABLE IF EXISTS `misivas`;
CREATE TABLE `misivas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `telefono` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `pais` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `ciudad` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `asunto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `mensaje` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 1509 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for modelos
-- ----------------------------
DROP TABLE IF EXISTS `modelos`;
CREATE TABLE `modelos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `alias` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `canales` int(0) NULL DEFAULT NULL,
  `canal0` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal1` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal2` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal3` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal4` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal5` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal6` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal7` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `canal8` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `especificaciones` varchar(5000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 136 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for modulos
-- ----------------------------
DROP TABLE IF EXISTS `modulos`;
CREATE TABLE `modulos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `componente` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `imagen` int(0) NULL DEFAULT NULL,
  `tipo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `modo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `opera` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `pines` smallint(0) NULL DEFAULT NULL,
  `comentarios` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 22 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for notificaciones
-- ----------------------------
DROP TABLE IF EXISTS `notificaciones`;
CREATE TABLE `notificaciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `icono` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `mensaje` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `destino` varchar(1000) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `leida` smallint(0) NULL DEFAULT NULL,
  `visible` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 69205 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for pagos
-- ----------------------------
DROP TABLE IF EXISTS `pagos`;
CREATE TABLE `pagos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `contrato` int(0) NULL DEFAULT NULL,
  `comprobante` int(0) NULL DEFAULT NULL,
  `monto` decimal(11, 2) NULL DEFAULT NULL,
  `medio` int(0) NULL DEFAULT NULL,
  `operacion` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 609 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for paises
-- ----------------------------
DROP TABLE IF EXISTS `paises`;
CREATE TABLE `paises`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `propagacion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for paneles
-- ----------------------------
DROP TABLE IF EXISTS `paneles`;
CREATE TABLE `paneles`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 223 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for parametros
-- ----------------------------
DROP TABLE IF EXISTS `parametros`;
CREATE TABLE `parametros`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `variable` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `valor` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `comentario` varchar(1024) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 61 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for perfiles
-- ----------------------------
DROP TABLE IF EXISTS `perfiles`;
CREATE TABLE `perfiles`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `tipo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `roles` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `rol` int(0) NULL DEFAULT NULL,
  `paneles` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL COMMENT 'paneles habilitados',
  `panel` int(0) NULL DEFAULT NULL COMMENT 'id del ultimo panel',
  `permisos` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 2804 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for permisos
-- ----------------------------
DROP TABLE IF EXISTS `permisos`;
CREATE TABLE `permisos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `sistema` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1134 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for planes
-- ----------------------------
DROP TABLE IF EXISTS `planes`;
CREATE TABLE `planes`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  `articulo` int(0) NULL DEFAULT NULL,
  `usuarios` int(0) NULL DEFAULT NULL,
  `dispositivos` int(0) NULL DEFAULT NULL,
  `usos` int(0) NULL DEFAULT NULL,
  `orden` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 151 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for productos
-- ----------------------------
DROP TABLE IF EXISTS `productos`;
CREATE TABLE `productos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `generacion` int(0) NULL DEFAULT NULL,
  `propiedades` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `presentado` datetime(0) NULL DEFAULT NULL,
  `discontinuado` datetime(0) NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  `sku` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `ean` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `web` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `documento` int(0) NULL DEFAULT NULL,
  `imagen` int(0) NULL DEFAULT NULL,
  `envase` int(0) NULL DEFAULT NULL,
  `empaque` int(0) NULL DEFAULT NULL,
  `manual` int(0) NULL DEFAULT NULL,
  `articulo` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 118 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for prospectos
-- ----------------------------
DROP TABLE IF EXISTS `prospectos`;
CREATE TABLE `prospectos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `empresa` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `rubro` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `contacto` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `celular` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `telefono` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `correo` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `web` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `domicilio` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `localidad` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `provincia` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `pais` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `registrado` datetime(0) NULL DEFAULT NULL,
  `contactado` datetime(0) NULL DEFAULT NULL,
  `actividades` int(0) NULL DEFAULT NULL,
  `interes` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `estado` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `cartera` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 267 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for provincias
-- ----------------------------
DROP TABLE IF EXISTS `provincias`;
CREATE TABLE `provincias`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `pais` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `categoria` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `ubicacion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `propagacion` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 101 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for publicidades
-- ----------------------------
DROP TABLE IF EXISTS `publicidades`;
CREATE TABLE `publicidades`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `fecha` date NULL DEFAULT NULL,
  `campana` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `canal` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for referencias
-- ----------------------------
DROP TABLE IF EXISTS `referencias`;
CREATE TABLE `referencias`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `metadatos` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `solicitud` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `respuesta` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `visible` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 112 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for registros
-- ----------------------------
DROP TABLE IF EXISTS `registros`;
CREATE TABLE `registros`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `sentido` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `canal` int(0) NULL DEFAULT NULL,
  `estado` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 17611365 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for roles
-- ----------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `sistema` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nivel` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  `menus` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `accesos` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `permisos` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `descripcion` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 210 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for senales
-- ----------------------------
DROP TABLE IF EXISTS `senales`;
CREATE TABLE `senales`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `serie` int(0) NULL DEFAULT NULL,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `sentido` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `transceptor` int(0) NULL DEFAULT NULL,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `canal` int(0) NULL DEFAULT NULL,
  `topic` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `mensaje` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 35861611 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for senales_por_minuto
-- ----------------------------
-- Agregado materializado de `senales`: una fila por minuto cerrado con la
-- cantidad de senales recibidas en ese minuto. Como las senales son
-- inmutables (solo se insertan), el count de un minuto pasado nunca
-- cambia y se cachea de por vida. Lo alimenta cloud/api/signals_stats.php
-- de forma incremental: bootstrap unico de 60 min y luego 1 fila por
-- minuto nuevo. Evita full scan sobre los ~35M registros de `senales`.
DROP TABLE IF EXISTS `senales_por_minuto`;
CREATE TABLE `senales_por_minuto` (
  `minuto`      datetime    NOT NULL,
  `cantidad`    int(10) UNSIGNED NOT NULL DEFAULT 0,
  `generado_at` timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`minuto`) USING BTREE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- ----------------------------
-- Table structure for sesiones
-- ----------------------------
DROP TABLE IF EXISTS `sesiones`;
CREATE TABLE `sesiones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `token` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `terminal` int(0) NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `perfil` int(0) NULL DEFAULT NULL,
  `mantener` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `iniciada` datetime(0) NULL DEFAULT NULL,
  `usada` datetime(0) NULL DEFAULT NULL,
  `expira` datetime(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 489996 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for sucesos
-- Legacy Reactor: log compartido con apps historicas (api / panel / www /
-- app). No la toca `cloud/`. El visor de sucesos del panel cloud usa
-- `sucesos_log` (mas abajo), que sigue el esquema estandar de la skill.
-- ----------------------------
DROP TABLE IF EXISTS `sucesos`;
CREATE TABLE `sucesos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `usuario` int(0) NULL DEFAULT NULL,
  `tipo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `fecha` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `detalle` varchar(2000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 4059556 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for sucesos_log
-- Log de actividad de los modulos de `cloud/`, leido por el Visor de
-- sucesos del panel. Los modulos escriben con registrarSuceso() (helper
-- api/lib/sucesos.php). Independiente de la tabla legacy `sucesos` para
-- no interferir con las apps historicas.
-- ----------------------------
DROP TABLE IF EXISTS `sucesos_log`;
CREATE TABLE `sucesos_log`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `origen` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `tipo` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'info',
  `detalle` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for talonarios
-- ----------------------------
DROP TABLE IF EXISTS `talonarios`;
CREATE TABLE `talonarios`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `empresa` int(0) NULL DEFAULT NULL,
  `tipo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `subtipo` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `punto` int(0) NULL DEFAULT NULL,
  `serie` int(0) NULL DEFAULT NULL,
  `fiscal` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `correo` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `web` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `fondo` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `terminos` varchar(5000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `estado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 53 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for tareas
-- ----------------------------
DROP TABLE IF EXISTS `tareas`;
CREATE TABLE `tareas`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `comando` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 3 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for temporizadores
-- ----------------------------
DROP TABLE IF EXISTS `temporizadores`;
CREATE TABLE `temporizadores`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `identificador` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `programa` int(0) NULL DEFAULT NULL,
  `cron` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitar` datetime(0) NULL DEFAULT NULL,
  `deshabilitar` datetime(0) NULL DEFAULT NULL,
  `primera` datetime(0) NULL DEFAULT NULL,
  `ultima` datetime(0) NULL DEFAULT NULL,
  `ejecuciones` int(0) NULL DEFAULT NULL,
  `habilitado` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 1083 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for terminales
-- ----------------------------
DROP TABLE IF EXISTS `terminales`;
CREATE TABLE `terminales`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `sistema` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `navegador` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `token` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `registrada` datetime(0) NULL DEFAULT NULL,
  `conectada` datetime(0) NULL DEFAULT NULL,
  `autorizada` smallint(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 485762 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for transceptores
-- ----------------------------
DROP TABLE IF EXISTS `transceptores`;
CREATE TABLE `transceptores`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `host` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `puerto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `usuario` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `contrasena` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `entrada` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 10 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for usos
-- ----------------------------
DROP TABLE IF EXISTS `usos`;
CREATE TABLE `usos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `fecha` date NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `dispositivo` int(0) NULL DEFAULT NULL,
  `entrantes` int(0) NULL DEFAULT NULL,
  `salientes` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 54857 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for usuarios
-- ----------------------------
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `usuario` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `autenticacion` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `contrasena` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `clave` varchar(6) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `correo` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `celular` varchar(15) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `registrante` int(0) NULL DEFAULT NULL,
  `registrado` datetime(0) NULL DEFAULT NULL,
  `ingresado` datetime(0) NULL DEFAULT NULL,
  `perfiles` int(0) NULL DEFAULT NULL,
  `perfil` int(0) NULL DEFAULT NULL,
  `roles` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `dominios` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `paneles` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `panel` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 2629 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for usuariosgrupos
-- ----------------------------
DROP TABLE IF EXISTS `usuariosgrupos`;
CREATE TABLE `usuariosgrupos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `nombre` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `habilitado` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for utilizaciones
-- ----------------------------
DROP TABLE IF EXISTS `utilizaciones`;
CREATE TABLE `utilizaciones`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `fecha` datetime(0) NULL DEFAULT NULL,
  `dominio` int(0) NULL DEFAULT NULL,
  `plan` int(0) NULL DEFAULT NULL,
  `usuarios` int(0) NULL DEFAULT NULL,
  `dispositivos` int(0) NULL DEFAULT NULL,
  `usos` int(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4161 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for videos
-- ----------------------------
DROP TABLE IF EXISTS `videos`;
CREATE TABLE `videos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `categoria` int(0) NULL DEFAULT NULL,
  `tipo` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `url` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `visibilidad` varchar(1) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 25 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for widget005
-- ----------------------------
DROP TABLE IF EXISTS `widget005`;
CREATE TABLE `widget005`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `periodo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `altas` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `bajas` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `resultado` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 7745 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for widgets
-- ----------------------------
DROP TABLE IF EXISTS `widgets`;
CREATE TABLE `widgets`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `dominio` int(0) NULL DEFAULT NULL,
  `panel` int(0) NULL DEFAULT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `tipo` int(0) NULL DEFAULT NULL,
  `objeto` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `identidad` int(0) NULL DEFAULT NULL,
  `orden` smallint(0) NULL DEFAULT NULL,
  `visible` smallint(0) NULL DEFAULT NULL,
  `parametros` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `estado` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `actualizado` datetime(0) NULL DEFAULT NULL,
  `usado` datetime(0) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 605 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for widgetstipos
-- ----------------------------
DROP TABLE IF EXISTS `widgetstipos`;
CREATE TABLE `widgetstipos`  (
  `id` int(0) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  `objeto` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT '',
  `modulos` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 17 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- View structure for articuloscomponentesvista
-- ----------------------------
DROP VIEW IF EXISTS `articuloscomponentesvista`;
CREATE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW `articuloscomponentesvista` AS select `articulos`.`nombre` AS `componenteNombre`,`articuloscomponentes`.`id` AS `id`,`articuloscomponentes`.`producto` AS `producto`,`articuloscomponentes`.`componente` AS `componente`,`articuloscomponentes`.`requiere` AS `requiere`,`articuloscomponentes`.`disponible` AS `disponible`,`articuloscomponentes`.`capacidad` AS `capacidad` from (`articulos` join `articuloscomponentes` on((`articuloscomponentes`.`componente` = `articulos`.`id`)));

-- ----------------------------
-- View structure for comprobantesvista
-- ----------------------------
DROP VIEW IF EXISTS `comprobantesvista`;
CREATE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW `comprobantesvista` AS select `talonarios`.`empresa` AS `talonarioEmpresa`,`talonarios`.`tipo` AS `talonarioTipo`,`talonarios`.`subtipo` AS `talonarioSubtipo`,`talonarios`.`punto` AS `talonarioPunto`,`talonarios`.`fiscal` AS `talonarioFiscal`,`comprobantes`.`id` AS `id`,`comprobantes`.`uuid` AS `uuid`,`comprobantes`.`talonario` AS `talonario`,`comprobantes`.`serie` AS `serie`,`comprobantes`.`caenro` AS `caenro`,`comprobantes`.`caevto` AS `caevto`,`comprobantes`.`caeres` AS `caeres`,`comprobantes`.`emision` AS `emision`,`comprobantes`.`vencimiento` AS `vencimiento`,`comprobantes`.`contrato` AS `contrato`,`comprobantes`.`cliente` AS `cliente`,`comprobantes`.`razon` AS `razon`,`comprobantes`.`condicion` AS `condicion`,`comprobantes`.`cuit` AS `cuit`,`comprobantes`.`domicilio` AS `domicilio`,`comprobantes`.`correo` AS `correo`,`comprobantes`.`celular` AS `celular`,`comprobantes`.`subtotal` AS `subtotal`,`comprobantes`.`iva` AS `iva`,`comprobantes`.`total` AS `total`,`comprobantes`.`cotizacion` AS `cotizacion`,`comprobantes`.`observaciones` AS `observaciones`,`comprobantes`.`comentarios` AS `comentarios`,`comprobantes`.`medio` AS `medio`,`comprobantes`.`estado` AS `estado` from (`comprobantes` join `talonarios` on((`comprobantes`.`talonario` = `talonarios`.`id`)));

-- ----------------------------
-- View structure for menusvista
-- ----------------------------
DROP VIEW IF EXISTS `menusvista`;
CREATE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW `menusvista` AS select `menus2`.`nombre` AS `padreNombre`,`menus2`.`nivel` AS `padreNivel`,`menus`.`id` AS `id`,`menus`.`nivel` AS `nivel`,`menus`.`padre` AS `padre`,`menus`.`orden` AS `orden`,`menus`.`icono` AS `icono`,`menus`.`nombre` AS `nombre`,`menus`.`destino` AS `destino`,`menus`.`ventana` AS `ventana`,`menus`.`inicio` AS `inicio`,`menus`.`estado` AS `estado` from (`menus` join `menus` `menus2` on((`menus`.`padre` = `menus2`.`id`)));

-- ----------------------------
-- View structure for perfilesvista
-- ----------------------------
DROP VIEW IF EXISTS `perfilesvista`;
CREATE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW `perfilesvista` AS select `dominios`.`nombre` AS `dominioNombre`,`usuarios`.`nombre` AS `usuarioNombre`,`usuarios`.`correo` AS `usuarioCorreo`,`usuarios`.`celular` AS `usuarioCelular`,`roles`.`nombre` AS `rolNombre`,`perfiles`.`id` AS `id`,`perfiles`.`uuid` AS `uuid`,`perfiles`.`nombre` AS `nombre`,`perfiles`.`usuario` AS `usuario`,`perfiles`.`dominio` AS `dominio`,`perfiles`.`tipo` AS `tipo`,`perfiles`.`roles` AS `roles`,`perfiles`.`rol` AS `rol`,`perfiles`.`paneles` AS `paneles`,`perfiles`.`panel` AS `panel`,`perfiles`.`permisos` AS `permisos`,`perfiles`.`habilitado` AS `habilitado` from (((`perfiles` left join `usuarios` on((`perfiles`.`usuario` = `usuarios`.`id`))) left join `dominios` on((`perfiles`.`dominio` = `dominios`.`id`))) left join `roles` on((`perfiles`.`rol` = `roles`.`id`)));

SET FOREIGN_KEY_CHECKS = 1;
