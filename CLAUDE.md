# reactor

El esquema de base de datos de referencia para todo el repositorio es [db/schema.sql](db/schema.sql). Consultarlo antes de proponer queries, endpoints, modelos o cambios que toquen datos: nombres de tablas, columnas, tipos, charsets y relaciones deben coincidir con lo definido ahí. Si una funcionalidad requiere una tabla o columna que no existe en `db/schema.sql`, proponer primero la modificación al esquema antes de escribir código que la asuma.
