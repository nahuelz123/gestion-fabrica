# Rapi Burguer · Gestión de fábrica

Aplicación de inventario, compras, recetas, producción, máquinas expendedoras y asistente Telegram para Rapi Burguer.

Tecnología: Laravel 12, Livewire 4, PHP 8.2+, MySQL y Tailwind/Vite. Aplicación responsive; web y cola de trabajos separadas en Railway.

## Módulos y límites

- Inventario por depósito y lote, movimientos, recuentos e importación Excel.
- Productos, presentaciones y recetas con conversiones a unidad base.
- Simulación y registro de producción; cancelaciones auditables de órdenes.
- Compras y proveedores, máquinas, ventas y Mercado Pago.
- Bot Telegram/Gemini: consultas de registros reales, contexto y confirmación obligatoria antes de escribir.
- Roles y permisos por empresa. El dueño administra; encargados gestionan únicamente las funciones autorizadas.

## Desarrollo

Requisitos: PHP, Composer, Node 22 y MySQL; extensiones zip y SimpleXML para XLSX.

    composer install
    cp .env.example .env
    php artisan key:generate
    # Configurar la base de datos en .env
    php artisan migrate
    npm ci
    npm run build
    php artisan serve

Trabajador de colas, en otro proceso:

    php artisan queue:work --tries=3

Las credenciales de Telegram, Gemini, Mercado Pago y MySQL no deben guardarse en GitHub ni en documentación pública.

## Importación y conciliación desde Excel

Ingresá desde **Inventario → Importar Excel**.

1. Elegí el depósito y descargá la plantilla CSV, que abre en Excel con códigos y cantidades existentes.
2. Editá las cantidades reales contadas, guardando como CSV o XLSX.
3. Subí la planilla. El sistema no modifica existencias: muestra la cantidad actual, cantidad Excel y diferencia por producto y lote.
4. Revisá todas las páginas de la comparación y confirmá para aplicar.

Campos admitidos: **Código** (SKU interno), **Producto** (nombre exacto), **Cantidad**, **Lote** y **Presentación**. Se requiere Cantidad y al menos uno de Código, Producto o código de barras. La presentación es opcional y usa el factor de conversión registrado para ese producto.

### Reglas de seguridad

- El archivo representa un **recuento absoluto** del depósito elegido; no una suma de entradas ni una producción nueva.
- Las filas ausentes **no ponen existencias a cero**. Una fila con cantidad 0 sí deja ese producto/lote a cero.
- Los productos loteados requieren un lote existente por fila; no se crea un lote nuevo en silencio.
- No se aceptan productos desconocidos, códigos ambiguos, filas duplicadas, cantidades negativas ni fórmulas en XLSX.
- Máximo 1000 filas y 4 MB por archivo. Formatos XLSX y CSV; XLS antiguo no.
- Los ajustes se hacen solo tras confirmación, con movimientos inmutables en el historial.
- Las diferencias se calculan bajo bloqueo transaccional: si otro usuario cambió stock tras la vista previa, se **rechaza toda la operación** y hay que volver a comparar.
- Un archivo ya aplicado en ese depósito no puede aplicarse de nuevo sin generar una planilla actualizada.
- No transforma lotes vencidos ni reemplaza los ingresos de compras, ventas o producción.

La lectura de XLSX usa extensiones nativas PHP zip y SimpleXML; si faltan, el sistema informa que debe usarse CSV o habilitarlas.

## Arquitectura: una responsabilidad por módulo

| Archivo o capa | Responsabilidad |
|---|---|
| Livewire Inventory/StockImportPage | Interfaz, selección del depósito, carga y confirmación |
| StockSpreadsheetReader | Lectura acotada y segura de archivos CSV/XLSX |
| StockImportService | Resolución de productos, comparación y aplicación de lote completo |
| StockRecountService | Ajuste absoluto de una posición de stock |
| StockTemplateExportService | Genera la plantilla CSV con datos actuales y escapado de fórmulas |
| StockService | Única escritura de movimientos y actualización transaccional de existencias |
| Production* | Cálculo, órdenes y reversión de producción |
| BotAgentService / GeminiService / BotAction* | Interpretación, contexto, confirmación y ejecución autorizada |
| Servicios MercadoPago | Cobros, conciliación y eventos externos |

El modelo de IA no puede modificar la base de datos por su cuenta: una acción tiene que pasar por autorización, vista previa y servicios de negocio.

## CI y control de calidad

    php artisan test
    php artisan route:list
    php artisan view:cache
    composer audit --locked
    npm audit --omit=dev --audit-level=high
    npm run build

GitHub Actions valida sintaxis, dependencias, migraciones MySQL, rutas, vistas, frontend y suite de pruebas para cada PR. No fusionar ni desplegar cambios que fallen CI.

## Lista de verificación previa al alta definitiva

- [ ] Confirmar un **backup automático de MySQL** y probar su restauración en entorno aislado; el volumen persistente no es un backup.
- [ ] Verificar variables de seguridad, HTTPS, webhooks, tokens y APP_DEBUG=false.
- [ ] Revisar Railway: aplicación web, trabajador de colas y base MySQL online, sin fallos ni colas bloqueadas.
- [ ] Probar operaciones reales con lotes, vencimientos, producción, devoluciones, permisos de dueño y encargado.
- [ ] Probar un cobro y reembolso controlados en Mercado Pago, y reconciliar webhooks.
- [ ] Probar preguntas ambiguas, confirmaciones y correcciones de producción en Telegram.
- [ ] Probar importación de un Excel real: comparar, confirmar, auditar movimientos y comprobar bloqueo de archivo repetido.
- [ ] Probar en celular, tablet y escritorio con usuarios auténticos.

**Los tests automatizados no sustituyen las comprobaciones operativas en producción.** Antes de una reestructuración profunda del bot o pagos, mantener PRs pequeños, tests de regresión y mecanismos de rollback.
