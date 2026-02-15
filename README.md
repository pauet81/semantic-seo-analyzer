# Semantico SEO

Aplicacion web para analisis semantico SEO con TF-IDF, Serper y OpenAI.

## Requisitos
- PHP 8.1+
- MySQL 5.7+ o MariaDB
- Extension cURL habilitada

## Instalacion
1. Importa el esquema SQL: `sql/schema.sql`
2. Copia `config.local.php.example` a `config.local.php` y completa claves y credenciales.
3. Configura el virtual host para servir la raiz del proyecto como document root.

## Uso
- Abre la app, introduce hasta 3 palabras clave y pulsa "Analizar".
- La API responde en `api/analyze.php`.

## Codespaces
1. Abre el repositorio en Codespaces.
2. Copia `config.local.php.example` a `config.local.php` y ajusta las claves.
3. Importa el esquema: `mysql -h db -u semantica_user -p semantica < sql/schema.sql`
4. Arranca el servidor PHP: `php -S 0.0.0.0:8080`
5. Abre el puerto 8080 en la pestaña de puertos de Codespaces.

## Notas
- Cache de analisis: 30 dias.
- Cache de SERP: 7 dias.
- Rate limit: 10 analisis por IP/24h.

## Admin
Los endpoints de soporte (`logs.php`, `debug.php`, `setup.php`, `test-openai.php`, `test-anthropic.php`, `clear-serp-cache.php`) requieren `admin.token`.
Puedes pasar el token via header `X-Admin-Token` o parametro `?token=`.
