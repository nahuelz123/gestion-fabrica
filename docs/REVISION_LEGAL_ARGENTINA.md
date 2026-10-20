# Revisión legal previa a publicar Rapi Burguer (Argentina)

> Documento de trabajo técnico, no un dictamen jurídico. No garantiza ausencia de reclamos, multas o demandas ni sustituye asesoramiento profesional. No publicar términos definitivos sin identificar al responsable y revisar la operatoria real.

## Situación comprobada en el código (20/10/2026)

- La app es un gestor interno con usuarios, recetas, inventario y producción, pero también hay una pantalla pública de máquinas con precio y QR Mercado Pago: **no es exclusivamente una intranet**.
- Existen avisos públicos `/privacidad` y `/cookies`. Ahora ambos se enlazan también en la pantalla pública de máquinas.
- La política de privacidad identifica por defecto al «Responsable de Gestión Fábrica», sin identificación jurídica; la app admite `PRIVACY_CONTACT_NAME` y `PRIVACY_CONTACT_EMAIL` pero esas variables **no estaban configuradas en el entorno Railway inspeccionado**.
- Hay acceso por roles y comprobación de firma del webhook Mercado Pago. Esto reduce riesgos técnicos, **no elimina responsabilidad legal**.
- No se comprobó de forma independiente la retención y eliminación de mensajes Telegram/Gemini, los flujos de solicitudes sobre datos ni la existencia de backups restaurables.
- No se verificó un proceso público para recibir **consultas, reclamos, cancelaciones y reembolsos** sobre las compras de las máquinas. No alcanza con una política de privacidad.

## Identificación obligatoria antes de completar los avisos

Solicitar a quien opera/vende efectivamente (no inventar):

1. Nombre o razón social, CUIT y domicilio comercial/legal.
2. Email, teléfono o canal de reclamos que realmente se atienda y horario de atención, cuando corresponda.
3. Precisar quién es **responsable** de los datos y quién presta solo desarrollo/hosting; definir roles contractuales y encargados de tratamiento.
4. Distinguir ventas en máquina física, ventas a distancia y uso interno; precisar quién es el vendedor que cobra y entrega, y quién gestiona reembolsos.
5. Inventariar datos personales, destinatarios (Railway, Telegram, Gemini/Google, Mercado Pago y Nominatim), finalidades y transferencias internacionales cuando correspondan.
6. Definir períodos de conservación y mecanismos efectivos de acceso, rectificación/supresión y atención de incidentes.
7. Determinar cómo informa precios, cargos, características, reclamos, devoluciones y, si corresponde, derechos de arrepentimiento y baja.
8. Acreditar autorizaciones para logos, marcas, textos, fotografías y contenido usado en el software.
9. Acordar por escrito con el cliente el alcance de desarrollo, licencias, seguridad, soporte, mantenimiento, costos de hosting y responsabilidad por datos e instrucciones de negocio.

## Derecho aplicable: revisar con profesional argentino

- **Ley 25.326:** datos personales, seguridad, información y ejercicio de derechos. Información al titular, canales y plazos reales. Fuente: https://www.argentina.gob.ar/normativa/nacional/64790/actualizacion
- **Ley 24.240:** información y protección del consumidor; cláusulas abusivas. Las cláusulas que excluyan toda responsabilidad no son una solución. Fuente: https://www.argentina.gob.ar/normativa/nacional/638/actualizacion
- **Disposición 954/2025:** vigente para comercio a distancia; reemplaza la Res. 424/2020 y regula botón de arrepentimiento, de baja, vías de atención y excepciones, incluida la de productos perecederos. **La excepción y el encuadre de las máquinas físicas deben evaluarse según la comercialización real**, no inferirse solamente porque se venden hamburguesas. Fuente: https://www.argentina.gob.ar/normativa/nacional/disposici%C3%B3n-954-2025-417152/texto
- Considerar normas alimentarias, habilitaciones y condiciones sanitarias, trazabilidad y rotulado si el sistema se vincula a venta o elaboración de alimentos. El software no sustituye obligaciones del operador.

## Documentos y procesos a finalizar

- Política de privacidad con **responsable real y contacto válido**, bases de tratamiento, proveedores, transferencias, conservación, medidas y derechos.
- Política de cookies verificada contra cookies y herramientas realmente presentes; pedir consentimiento cuando sea exigible para tecnologías opcionales.
- Términos de uso para **usuarios internos**: alcance, autorización, responsabilidades de ingreso y revisión de datos, trazabilidad, licencias, seguridad, cambios del servicio y soporte. No renunciar a derechos irrenunciables.
- Condiciones de venta y atención **para compradores de máquinas**, cuando corresponda: vendedor identificado, precio, forma de pago, entrega, reclamos y devoluciones.
- Contrato **desarrollador–operador** separado de los términos de consumidores. Debe definir quién mantiene hosting, copias, incidentes, propiedad intelectual y terceros.
- Procedimiento probado de respuesta a solicitudes de titulares de datos, reclamos, eventos de seguridad y restauración de backups.

## Regla de publicación

**No desplegar un texto que diga «no se puede demandar», «el sistema no responde por ningún daño» o «el consumidor renuncia a reclamar».** Tampoco inventar CUIT, domicilio o email. Revisión profesional antes de lanzar una política contractual.

La UI puede enlazar la política de privacidad y cookies ya existente; los documentos contractuales nuevos deben esperar a que el responsable complete información y los apruebe.
